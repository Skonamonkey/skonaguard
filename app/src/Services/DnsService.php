<?php

declare(strict_types=1);

namespace SkonaGuard\Services;

use SkonaGuard\Models\Database;

class DnsService
{
    private const HOSTS_FILE = '/etc/hosts';
    private const MARKER     = 'SKONAGUARD';

    public function __construct(private Database $db) {}

    public function isEnabled(): bool
    {
        return ($this->db->queryOne("SELECT value FROM settings WHERE key = 'dns_enabled'")['value'] ?? '0') === '1';
    }

    public function getDomain(): string
    {
        return trim($this->db->queryOne("SELECT value FROM settings WHERE key = 'dns_domain'")['value'] ?? 'skona');
    }

    public function getUpstream(): string
    {
        return trim($this->db->queryOne("SELECT value FROM settings WHERE key = 'dns_upstream'")['value'] ?? '9.9.9.9');
    }

    public function getHubIp(): string
    {
        $ip = trim((string) shell_exec("ip -4 addr show wg0 2>/dev/null | grep -oP '(?<=inet )[\d.]+' | head -1"));
        return $ip ?: ($_ENV['WG_SUBNET_HUB'] ?? '172.16.0.1');
    }

    public function sync(): void
    {
        $this->generateHostsFile();
        shell_exec('supervisorctl -c /etc/supervisord.conf restart dns 2>/dev/null');
    }

    public function generateHostsFile(): void
    {
        if (!$this->isEnabled()) {
            $this->writeMarkerBlock('');
            return;
        }

        $domain = ltrim($this->getDomain(), '.');
        $lines  = [];

        $peers = $this->db->query("
            SELECT p.vpn_ip, p.hostname, p.dns_alias, z.dns_name as zone_dns_name
            FROM peers p
            JOIN zones z ON z.id = p.zone_id
            WHERE p.enabled = 1
        ");

        foreach ($peers as $peer) {
            $ip = $peer['vpn_ip'] ?? '';
            if (!$ip) continue;

            if ($peer['hostname'] && $peer['zone_dns_name']) {
                $lines[] = $ip . "\t" . strtolower($peer['hostname']) . '.' . strtolower($peer['zone_dns_name']) . '.' . $domain;
            }

            if ($peer['dns_alias']) {
                $lines[] = $ip . "\t" . strtolower($peer['dns_alias']) . '.' . $domain;
            }
        }

        $zones = $this->db->query("
            SELECT z.id, z.dns_name
            FROM zones z
            WHERE z.dns_name IS NOT NULL AND z.dns_name != ''
        ");

        foreach ($zones as $zone) {
            $gateway = $this->db->queryOne(
                "SELECT vpn_ip FROM peers WHERE zone_id = ? AND is_gateway = 1 AND enabled = 1 LIMIT 1",
                [(int) $zone['id']]
            );
            if ($gateway) {
                $lines[] = $gateway['vpn_ip'] . "\t" . strtolower($zone['dns_name']) . '.' . $domain;
            }
        }

        $this->writeMarkerBlock(implode("\n", $lines));
    }

    private function writeMarkerBlock(string $block): void
    {
        $path    = self::HOSTS_FILE;
        $marker  = self::MARKER;
        $begin   = "# {$marker} BEGIN";
        $end     = "# {$marker} END";
        $current = file_exists($path) ? (string) file_get_contents($path) : '';

        if (str_contains($current, $begin)) {
            $current = (string) preg_replace(
                '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\n?/s',
                '',
                $current
            );
        }

        $current = rtrim($current);
        if ($block !== '') {
            $block = implode("\n", array_filter(array_map('trim', explode("\n", $block))));
            $current .= "\n{$begin}\n{$block}\n{$end}\n";
        }

        file_put_contents($path, $current);
    }
}
