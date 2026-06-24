<?php

declare(strict_types=1);

$dbPath    = '/app/database/skonaguard.db';
$hostsFile = '/etc/dnsproxy/skonaguard.hosts';

if (!file_exists($dbPath)) {
    exit(0);
}

$db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$enabled = $db->query("SELECT value FROM settings WHERE key = 'dns_enabled'")->fetchColumn();
if ($enabled !== '1') {
    file_put_contents($hostsFile, '');
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

    $names = [];

    if ($peer['hostname'] && $peer['zone_dns_name']) {
        $names[] = strtolower($peer['hostname']) . '.' . strtolower($peer['zone_dns_name']) . '.' . $domain;
    }

    if ($peer['dns_alias']) {
        $names[] = strtolower($peer['dns_alias']) . '.' . $domain;
    }

    foreach ($names as $name) {
        $lines[] = $ip . "\t" . $name;
    }
}

$zones = $db->query("
    SELECT z.id, z.dns_name, z.subnet
    FROM zones z
    WHERE z.dns_name IS NOT NULL AND z.dns_name != ''
");

foreach ($zones->fetchAll(PDO::FETCH_ASSOC) as $zone) {
    $gatewayIp = trim((string) shell_exec(
        "ip -4 addr show wg0 2>/dev/null | grep -oP '(?<=inet )[\d.]+' | head -1"
    ));

    $peerGateway = $db->query("
        SELECT p.vpn_ip FROM peers p WHERE p.zone_id = {$zone['id']} AND p.is_gateway = 1 AND p.enabled = 1 LIMIT 1
    ")->fetchColumn();

    $ip = $peerGateway ?: $gatewayIp;
    if ($ip) {
        $lines[] = $ip . "\t" . strtolower($zone['dns_name']) . '.' . $domain;
    }
}

file_put_contents($hostsFile, implode("\n", $lines) . "\n");
