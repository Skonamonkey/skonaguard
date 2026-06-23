<?php

declare(strict_types=1);

$dbPath = '/app/database/skonaguard.db';
if (!file_exists($dbPath)) {
    exit(0);
}

function buildPortArgs(string $port): string
{
    $port = str_replace('-', ':', trim($port));
    if (str_contains($port, ',')) {
        return '-m multiport --dports ' . escapeshellarg($port);
    }
    return '--dport ' . escapeshellarg($port);
}

try {
    $db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $enforcement = $db->query("SELECT value FROM settings WHERE key = 'acl_enforcement'")->fetchColumn();
    if ($enforcement !== '1') {
        shell_exec('iptables -D FORWARD -i wg0 -j SKONAGUARD 2>/dev/null');
        shell_exec('iptables -F SKONAGUARD 2>/dev/null');
        shell_exec('iptables -X SKONAGUARD 2>/dev/null');
        exit(0);
    }

    shell_exec('iptables -N SKONAGUARD 2>/dev/null');
    $check = shell_exec('iptables -C FORWARD -i wg0 -j SKONAGUARD 2>&1');
    if ($check !== null && trim($check) !== '') {
        shell_exec('iptables -I FORWARD 1 -i wg0 -j SKONAGUARD 2>/dev/null');
    }

    shell_exec('iptables -F SKONAGUARD 2>/dev/null');

    $serverWgIp = trim((string) shell_exec("ip -4 addr show wg0 2>/dev/null | grep -oP '(?<=inet )[\d.]+' | head -1"));
    if ($serverWgIp) {
        shell_exec('iptables -A SKONAGUARD -d ' . escapeshellarg($serverWgIp) . ' -j ACCEPT 2>/dev/null');
        shell_exec('iptables -A SKONAGUARD -s ' . escapeshellarg($serverWgIp) . ' -j ACCEPT 2>/dev/null');
    }

    $stmt = $db->query("
        SELECT r.*, sz.subnet as src_subnet, dz.subnet as dst_subnet
        FROM acl_rules r
        LEFT JOIN zones sz ON sz.id = r.src_zone_id
        LEFT JOIN zones dz ON dz.id = r.dst_zone_id
        ORDER BY r.priority ASC, r.id ASC
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
        $src     = $rule['src_ip_override'] ?: ($rule['src_subnet'] ?? null);
        $dst     = $rule['dst_ip_override'] ?: ($rule['dst_subnet'] ?? null);
        $dstPort = $rule['dst_port'] ?? null;
        $type    = $rule['rule_type'];

        $s  = $src ? '-s ' . escapeshellarg($src) : '';
        $d  = $dst ? '-d ' . escapeshellarg($dst) : '';
        $rs = $dst ? '-s ' . escapeshellarg($dst) : '';
        $rd = $src ? '-d ' . escapeshellarg($src) : '';

        if ($dstPort && $type !== 'icmp_only') {
            $portArg = buildPortArgs($dstPort);
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
            continue;
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

    $defaultPolicy = $db->query("SELECT value FROM settings WHERE key = 'acl_default_policy'")->fetchColumn();
    if ($defaultPolicy === 'restrictive') {
        shell_exec('iptables -A SKONAGUARD -j DROP 2>/dev/null');
    } else {
        shell_exec('iptables -A SKONAGUARD -j RETURN 2>/dev/null');
    }

} catch (Throwable $e) {
    exit(1);
}
