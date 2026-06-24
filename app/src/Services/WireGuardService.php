<?php

declare(strict_types=1);

namespace SkonaGuard\Services;

use SkonaGuard\Models\Database;

class WireGuardService
{
    private const WG_CONF      = '/etc/wireguard/wg0.conf';
    private const WG_INTERFACE = 'wg0';
    private const WGQUICK_SKIP = ['address','postup','postdown','preup','predown','dns','saveconfig','table','mtu'];

    public function __construct(private Database $db) {}

    public function generateKeys(): array
    {
        $private   = trim((string) shell_exec('wg genkey'));
        $public    = trim((string) shell_exec("echo " . escapeshellarg($private) . " | wg pubkey"));
        $preshared = trim((string) shell_exec('wg genpsk'));
        return ['private' => $private, 'public' => $public, 'preshared' => $preshared];
    }

    public function allocateIp(int|string $zoneId): string
    {
        $zone = $this->db->queryOne("SELECT subnet FROM zones WHERE id = ?", [(int) $zoneId]);
        if (!$zone) throw new \RuntimeException("Zone not found");

        [$network, $prefix] = explode('/', $zone['subnet']);
        $base   = ip2long($network);
        $used   = array_column(
            $this->db->query("SELECT vpn_ip FROM peers WHERE zone_id = ?", [(int) $zoneId]),
            'vpn_ip'
        );
        $usedLongs = array_map('ip2long', $used);

        for ($i = 2; $i < 254; $i++) {
            $candidate = long2ip($base + $i);
            if (!in_array(ip2long($candidate), $usedLongs, true)) {
                return $candidate;
            }
        }
        throw new \RuntimeException("No available IPs in zone subnet");
    }

    public function generateClientConfig(int|string $peerId, string $mode = 'wan'): string
    {
        $peer = $this->db->queryOne(
            "SELECT p.*, z.subnet as zone_subnet FROM peers p JOIN zones z ON z.id = p.zone_id WHERE p.id = ?",
            [(int) $peerId]
        );
        if (!$peer) throw new \RuntimeException("Peer not found");

        $profile = null;
        if ($peer['profile_id']) {
            $profile = $this->db->queryOne("SELECT * FROM profiles WHERE id = ?", [(int) $peer['profile_id']]);
        }

        $serverPublicKey = $this->db->queryOne("SELECT value FROM settings WHERE key = 'server_public_key'")['value'] ?? '';
        $serverWanIp     = $this->db->queryOne("SELECT value FROM settings WHERE key = 'server_public_ip'")['value'] ?? '';
        $serverLanIp     = $this->db->queryOne("SELECT value FROM settings WHERE key = 'server_lan_ip'")['value'] ?? '';
        $wgPort          = $this->db->queryOne("SELECT value FROM settings WHERE key = 'wg_port'")['value'] ?? '51820';
        $serverIp        = ($mode === 'lan' && $serverLanIp) ? $serverLanIp : $serverWanIp;

        [$network, $prefix] = explode('/', $peer['zone_subnet']);
        $clientAddress = $peer['vpn_ip'] . '/' . $prefix;

        $allowedIps = $peer['custom_allowed_ips'] ?? ($profile['custom_allowed_ips'] ?? null) ?? ($_ENV['WG_SUBNET'] ?? '172.16.0.0/16');
        $dns        = $peer['dns'] ?? ($profile['dns'] ?? '') ?? '';

        if ($dns === 'skip') {
            $dns = '';
        } elseif ($dns === '') {
            $dnsEnabled = ($this->db->queryOne("SELECT value FROM settings WHERE key = 'dns_enabled'")['value'] ?? '0') === '1';
            if ($dnsEnabled) {
                $hubIp = trim((string) shell_exec("ip -4 addr show wg0 2>/dev/null | grep -oP '(?<=inet )[\d.]+' | head -1"));
                $dns   = $hubIp ?: ($_ENV['WG_SUBNET_HUB'] ?? '172.16.0.1');
                $wgSubnet    = $_ENV['WG_SUBNET'] ?? ($this->db->queryOne("SELECT value FROM settings WHERE key = 'wg_subnet'")['value'] ?? '172.16.0.0/16');
                $reverseZone = $this->buildReverseZone($wgSubnet);
                if ($reverseZone !== '') {
                    $dns .= ', ~' . $reverseZone;
                }
            }
        }

        $conf  = "[Interface]\n";
        $conf .= "PrivateKey = {$peer['private_key']}\n";
        $conf .= "Address = {$clientAddress}\n";
        if ($dns !== '') {
            $conf .= "DNS = {$dns}\n";
        }
        $conf .= "\n";
        $conf .= "[Peer]\n";
        $conf .= "PublicKey = {$serverPublicKey}\n";
        $conf .= "PresharedKey = {$peer['preshared_key']}\n";
        $conf .= "AllowedIPs = {$allowedIps}\n";
        $conf .= "Endpoint = {$serverIp}:{$wgPort}\n";
        $conf .= "PersistentKeepalive = 25\n";

        return $conf;
    }

    public function syncConfig(): bool
    {
        $serverPrivateKey = $this->db->queryOne("SELECT value FROM settings WHERE key = 'server_private_key'")['value'] ?? '';
        $wgPort           = $this->db->queryOne("SELECT value FROM settings WHERE key = 'wg_port'")['value'] ?? '51820';
        $wgSubnetHub      = $_ENV['WG_SUBNET_HUB'] ?? '172.16.0.1';
        $wgSubnet         = $_ENV['WG_SUBNET'] ?? '172.16.0.0/16';
        [, $prefix]       = explode('/', $wgSubnet);
        $eth              = $this->detectEgressInterface();

        $conf  = "[Interface]\n";
        $conf .= "PrivateKey = {$serverPrivateKey}\n";
        $conf .= "Address = {$wgSubnetHub}/{$prefix}\n";
        $conf .= "ListenPort = {$wgPort}\n";
        $conf .= "PostUp = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -A FORWARD -o wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o {$eth} -j MASQUERADE\n";
        $conf .= "PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -D FORWARD -o wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o {$eth} -j MASQUERADE; iptables -D FORWARD -i wg0 -j SKONAGUARD 2>/dev/null; iptables -F SKONAGUARD 2>/dev/null; iptables -X SKONAGUARD 2>/dev/null\n";
        $conf .= "\n";

        $peers = $this->db->query("SELECT * FROM peers WHERE enabled = 1");
        foreach ($peers as $peer) {
            $profile = null;
            if ($peer['profile_id']) {
                $profile = $this->db->queryOne("SELECT * FROM profiles WHERE id = ?", [(int) $peer['profile_id']]);
            }
            $isGateway     = $peer['is_gateway'] ?? ($profile['is_gateway'] ?? 0);
            $gatewaySubnet = $peer['gateway_subnet'] ?? ($profile['gateway_subnet'] ?? null);

            $allowedIps = $peer['vpn_ip'] . '/32';
            if ($isGateway && $gatewaySubnet) {
                $allowedIps .= ', ' . $gatewaySubnet;
            }

            $conf .= "[Peer]\n";
            $conf .= "# {$peer['name']}\n";
            $conf .= "PublicKey = {$peer['public_key']}\n";
            $conf .= "PresharedKey = {$peer['preshared_key']}\n";
            $conf .= "AllowedIPs = {$allowedIps}\n";
            if ($isGateway) {
                $conf .= "PersistentKeepalive = 25\n";
            }
            $conf .= "\n";
        }

        file_put_contents(self::WG_CONF, $conf);

        $stripped = $this->stripWgQuickConf($conf);
        $tmp = tempnam('/tmp', 'wg-sync-');
        file_put_contents($tmp, $stripped);

        $attempts = 0;
        do {
            $out = shell_exec("wg syncconf " . self::WG_INTERFACE . " " . escapeshellarg($tmp) . " 2>&1");
            if ($out === null || $out === '') break;
            $attempts++;
            if ($attempts < 3) sleep(1);
        } while ($attempts < 3);

        unlink($tmp);

        $this->syncAcl();

        return true;
    }

    public function syncAcl(): void
    {
        $enforcement = $this->db->queryOne("SELECT value FROM settings WHERE key = 'acl_enforcement'")['value'] ?? '0';

        if ($enforcement !== '1') {
            $this->teardownAclChain();
            return;
        }

        $this->setupAclChain();
        shell_exec('iptables -F SKONAGUARD 2>/dev/null');

        shell_exec('iptables -A SKONAGUARD -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT 2>/dev/null');

        $serverWgIp = trim((string) shell_exec("ip -4 addr show wg0 2>/dev/null | grep -E 'inet ' | awk '{print \$2}' | cut -d/ -f1 | head -1"));
        if (!$serverWgIp) {
            $serverWgIp = $_ENV['WG_SUBNET_HUB'] ?? '';
        }
        if ($serverWgIp) {
            shell_exec('iptables -A SKONAGUARD -d ' . escapeshellarg($serverWgIp) . ' -j ACCEPT 2>/dev/null');
            shell_exec('iptables -A SKONAGUARD -s ' . escapeshellarg($serverWgIp) . ' -j ACCEPT 2>/dev/null');
        }

        $dockerGw = trim((string) @file_get_contents('/tmp/docker_gw'));
        if ($dockerGw) {
            shell_exec('iptables -A SKONAGUARD -d ' . escapeshellarg($dockerGw) . ' -j ACCEPT 2>/dev/null');
            shell_exec('iptables -A SKONAGUARD -s ' . escapeshellarg($dockerGw) . ' -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT 2>/dev/null');
        }

        $rules = $this->db->query("
            SELECT r.*, sz.subnet as src_subnet, dz.subnet as dst_subnet
            FROM acl_rules r
            LEFT JOIN zones sz ON sz.id = r.src_zone_id
            LEFT JOIN zones dz ON dz.id = r.dst_zone_id
            ORDER BY r.priority ASC, r.id ASC
        ");

        foreach ($rules as $rule) {
            $src = $rule['src_ip_override'] ?: ($rule['src_subnet'] ?? null);
            $dst = $rule['dst_ip_override'] ?: ($rule['dst_subnet'] ?? null);
            $this->applyAclRule($rule['rule_type'], $src, $dst, $rule['dst_port'] ?? null);
        }

        $defaultPolicy = $this->db->queryOne("SELECT value FROM settings WHERE key = 'acl_default_policy'")['value'] ?? 'permissive';
        if ($defaultPolicy === 'restrictive') {
            shell_exec('iptables -A SKONAGUARD -j DROP 2>/dev/null');
        } else {
            shell_exec('iptables -A SKONAGUARD -j RETURN 2>/dev/null');
        }
    }

    private function setupAclChain(): void
    {
        shell_exec('iptables -N SKONAGUARD 2>/dev/null');
        $check = shell_exec('iptables -C FORWARD -i wg0 -j SKONAGUARD 2>&1');
        if ($check !== null && trim($check) !== '') {
            shell_exec('iptables -I FORWARD 1 -i wg0 -j SKONAGUARD 2>/dev/null');
        }
    }

    private function teardownAclChain(): void
    {
        shell_exec('iptables -D FORWARD -i wg0 -j SKONAGUARD 2>/dev/null');
        shell_exec('iptables -F SKONAGUARD 2>/dev/null');
        shell_exec('iptables -X SKONAGUARD 2>/dev/null');
    }

    private function applyAclRule(string $type, ?string $src, ?string $dst, ?string $dstPort = null): void
    {
        $s  = $src ? '-s ' . escapeshellarg($src) : '';
        $d  = $dst ? '-d ' . escapeshellarg($dst) : '';
        $rs = $dst ? '-s ' . escapeshellarg($dst) : '';
        $rd = $src ? '-d ' . escapeshellarg($src) : '';

        if ($dstPort && $type !== 'icmp_only') {
            $portArg = $this->buildPortArgs($dstPort);
            foreach (['tcp', 'udp'] as $proto) {
                $fwd = trim("iptables -A SKONAGUARD {$s} {$d} -p {$proto} {$portArg}");
                $rev = trim("iptables -A SKONAGUARD {$rs} {$rd} -p {$proto}");
                switch ($type) {
                    case 'full':
                        shell_exec("{$fwd} -j ACCEPT 2>/dev/null");
                        shell_exec("{$rev} -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT 2>/dev/null");
                        break;
                    case 'established':
                        shell_exec("{$fwd} -j ACCEPT 2>/dev/null");
                        shell_exec("{$rev} -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT 2>/dev/null");
                        shell_exec("{$rev} -m conntrack --ctstate NEW -j DROP 2>/dev/null");
                        break;
                    case 'deny':
                        shell_exec("{$fwd} -j DROP 2>/dev/null");
                        shell_exec("{$rev} -m conntrack --ctstate NEW -j DROP 2>/dev/null");
                        break;
                }
            }
            return;
        }

        $fwd = trim("iptables -A SKONAGUARD {$s} {$d}");
        $rev = trim("iptables -A SKONAGUARD {$rs} {$rd}");

        switch ($type) {
            case 'full':
                shell_exec("{$fwd} -j ACCEPT 2>/dev/null");
                shell_exec("{$rev} -j ACCEPT 2>/dev/null");
                break;

            case 'established':
                shell_exec("{$fwd} -j ACCEPT 2>/dev/null");
                shell_exec("{$rev} -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT 2>/dev/null");
                shell_exec("{$rev} -j DROP 2>/dev/null");
                break;

            case 'icmp_only':
                shell_exec("{$fwd} -p icmp -j ACCEPT 2>/dev/null");
                shell_exec("{$rev} -p icmp -j ACCEPT 2>/dev/null");
                shell_exec("{$fwd} -j DROP 2>/dev/null");
                shell_exec("{$rev} -j DROP 2>/dev/null");
                break;

            case 'deny':
                shell_exec("{$fwd} -j DROP 2>/dev/null");
                shell_exec("{$rev} -j DROP 2>/dev/null");
                break;
        }
    }

    private function buildPortArgs(string $port): string
    {
        $port = str_replace('-', ':', trim($port));
        if (str_contains($port, ',')) {
            return '-m multiport --dports ' . escapeshellarg($port);
        }
        return '--dport ' . escapeshellarg($port);
    }

    private function stripWgQuickConf(string $conf): string
    {
        $lines = [];
        foreach (explode("\n", $conf) as $line) {
            $key = strtolower(explode('=', $line, 2)[0]);
            $key = trim($key);
            if (!in_array($key, self::WGQUICK_SKIP, true)) {
                $lines[] = $line;
            }
        }
        return implode("\n", $lines);
    }

    private function detectEgressInterface(): string
    {
        $out = trim((string) shell_exec("ip route get 8.8.8.8 2>/dev/null | awk '/dev/{for(i=1;i<=NF;i++) if(\$i==\"dev\") print \$(i+1)}'"));
        return $out ?: 'eth0';
    }

    private function buildReverseZone(string $subnet): string
    {
        if (!str_contains($subnet, '/')) {
            return '';
        }
        [$network, $prefix] = explode('/', $subnet, 2);
        $octets = explode('.', $network);
        $count  = (int) ceil((int) $prefix / 8);
        $count  = max(1, min(4, $count));
        return implode('.', array_reverse(array_slice($octets, 0, $count))) . '.in-addr.arpa';
    }
}
