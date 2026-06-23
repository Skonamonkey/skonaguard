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

    public function allocateIp(int $zoneId): string
    {
        $zone = $this->db->queryOne("SELECT subnet FROM zones WHERE id = ?", [$zoneId]);
        if (!$zone) throw new \RuntimeException("Zone not found");

        [$network, $prefix] = explode('/', $zone['subnet']);
        $base   = ip2long($network);
        $used   = array_column(
            $this->db->query("SELECT vpn_ip FROM peers WHERE zone_id = ?", [$zoneId]),
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

    public function generateClientConfig(int $peerId): string
    {
        $peer = $this->db->queryOne(
            "SELECT p.*, z.subnet as zone_subnet FROM peers p JOIN zones z ON z.id = p.zone_id WHERE p.id = ?",
            [$peerId]
        );
        if (!$peer) throw new \RuntimeException("Peer not found");

        $serverPublicKey = $this->db->queryOne("SELECT value FROM settings WHERE key = 'server_public_key'")['value'] ?? '';
        $serverIp        = $this->db->queryOne("SELECT value FROM settings WHERE key = 'server_public_ip'")['value'] ?? '';
        $wgPort          = $this->db->queryOne("SELECT value FROM settings WHERE key = 'wg_port'")['value'] ?? '51820';

        [$network, $prefix] = explode('/', $peer['zone_subnet']);
        $clientAddress = $peer['vpn_ip'] . '/' . $prefix;

        $allowedIps = $peer['custom_allowed_ips'] ?: '172.16.0.0/16';
        $dns        = $peer['dns'] ?: '1.1.1.1';

        $conf  = "[Interface]\n";
        $conf .= "PrivateKey = {$peer['private_key']}\n";
        $conf .= "Address = {$clientAddress}\n";
        $conf .= "DNS = {$dns}\n";
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
        $conf .= "PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -D FORWARD -o wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o {$eth} -j MASQUERADE\n";
        $conf .= "\n";

        $peers = $this->db->query("SELECT * FROM peers WHERE enabled = 1");
        foreach ($peers as $peer) {
            $allowedIps = $peer['vpn_ip'] . '/32';
            if ($peer['is_gateway'] && $peer['gateway_subnet']) {
                $allowedIps .= ', ' . $peer['gateway_subnet'];
            }

            $conf .= "[Peer]\n";
            $conf .= "# {$peer['name']}\n";
            $conf .= "PublicKey = {$peer['public_key']}\n";
            $conf .= "PresharedKey = {$peer['preshared_key']}\n";
            $conf .= "AllowedIPs = {$allowedIps}\n";
            if ($peer['is_gateway']) {
                $conf .= "PersistentKeepalive = 25\n";
            }
            $conf .= "\n";
        }

        file_put_contents(self::WG_CONF, $conf);

        $stripped = $this->stripWgQuickConf($conf);
        $tmp = tempnam('/tmp', 'wg-sync-');
        file_put_contents($tmp, $stripped);
        $out = shell_exec("wg syncconf " . self::WG_INTERFACE . " " . escapeshellarg($tmp) . " 2>&1");
        unlink($tmp);

        return true;
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
        $out = trim((string) shell_exec("ip route get 8.8.8.8 2>/dev/null | grep -oP 'dev \K\S+'"));
        return $out ?: 'eth0';
    }
}
