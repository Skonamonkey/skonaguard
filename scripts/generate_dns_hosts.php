<?php

declare(strict_types=1);

$dbPath    = '/app/database/skonaguard.db';
$hostsFile = '/etc/hosts';
$marker    = 'SKONAGUARD';

function replaceMarkerBlock(string $path, string $marker, string $block): void
{
    $current = file_exists($path) ? file_get_contents($path) : '';
    $begin   = "# {$marker} BEGIN";
    $end     = "# {$marker} END";

    if (str_contains($current, $begin)) {
        $current = preg_replace('/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\n?/s', '', $current);
    }

    $current = rtrim($current);
    if ($block !== '') {
        $block = implode("\n", array_filter(array_map('trim', explode("\n", $block))));
        $current .= "\n{$begin}\n{$block}\n{$end}\n";
    }

    file_put_contents($path, $current);
}

if (!file_exists($dbPath)) {
    replaceMarkerBlock($hostsFile, $marker, '');
    exit(0);
}

$db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$enabled = $db->query("SELECT value FROM settings WHERE key = 'dns_enabled'")->fetchColumn();
if ($enabled !== '1') {
    replaceMarkerBlock($hostsFile, $marker, '');
    exit(0);
}

$domain = trim((string) ($db->query("SELECT value FROM settings WHERE key = 'dns_domain'")->fetchColumn() ?: 'skona'));
$domain = ltrim($domain, '.');

$lines = [];

$peers = $db->query("
    SELECT p.vpn_ip, p.hostname, p.dns_alias, z.dns_name as zone_dns_name
    FROM peers p
    JOIN zones z ON z.id = p.zone_id
    WHERE p.enabled = 1
");

foreach ($peers->fetchAll(PDO::FETCH_ASSOC) as $peer) {
    $ip = $peer['vpn_ip'];
    if (!$ip) continue;

    if ($peer['hostname'] && $peer['zone_dns_name']) {
        $lines[] = $ip . "\t" . strtolower($peer['hostname']) . '.' . strtolower($peer['zone_dns_name']) . '.' . $domain;
    }

    if ($peer['dns_alias']) {
        $lines[] = $ip . "\t" . strtolower($peer['dns_alias']) . '.' . $domain;
    }
}

$zones = $db->query("
    SELECT z.id, z.dns_name
    FROM zones z
    WHERE z.dns_name IS NOT NULL AND z.dns_name != ''
");

foreach ($zones->fetchAll(PDO::FETCH_ASSOC) as $zone) {
    $peerGateway = $db->query("
        SELECT p.vpn_ip FROM peers p WHERE p.zone_id = {$zone['id']} AND p.is_gateway = 1 AND p.enabled = 1 LIMIT 1
    ")->fetchColumn();

    if ($peerGateway) {
        $lines[] = $peerGateway . "\t" . strtolower($zone['dns_name']) . '.' . $domain;
    }
}

replaceMarkerBlock($hostsFile, $marker, implode("\n", $lines));
