<?php

declare(strict_types=1);

namespace SkonaGuard\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SkonaGuard\Models\Database;
use SkonaGuard\Services\DnsService;
use SkonaGuard\Services\WireGuardService;

class PeersController
{
    public function __construct(
        private Twig $view,
        private Database $db,
        private WireGuardService $wg,
        private DnsService $dns
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $peers = $this->db->query("
            SELECT p.*, z.name as zone_name, z.subnet as zone_subnet, pr.name as profile_name
            FROM peers p
            JOIN zones z ON z.id = p.zone_id
            LEFT JOIN profiles pr ON pr.id = p.profile_id
            ORDER BY z.name, p.name
        ");
        $zones    = $this->db->query("SELECT * FROM zones ORDER BY name");
        $profiles = $this->db->query("SELECT * FROM profiles ORDER BY name");
        $lanIp    = $this->db->queryOne("SELECT value FROM settings WHERE key = 'server_lan_ip'")['value'] ?? '';

        return $this->view->render($response, 'peers/index.twig', [
            'active_nav'      => 'peers',
            'peers'           => $peers,
            'zones'           => $zones,
            'profiles'        => $profiles,
            'has_lan_endpoint' => $lanIp !== '',
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body      = (array) $request->getParsedBody();
        $name      = trim($body['name'] ?? '');
        $profileId = ($body['profile_id'] ?? '') !== '' ? (int) $body['profile_id'] : null;
        $notes     = trim($body['notes'] ?? '');

        $profile = null;
        if ($profileId) {
            $profile = $this->db->queryOne("SELECT * FROM profiles WHERE id = ?", [$profileId]);
        }

        $zoneId        = (int) ($body['zone_id'] ?? ($profile['zone_id'] ?? 0));
        $dnsServer     = trim($body['dns'] ?? '');
        $isGateway     = isset($body['is_gateway']) ? 1 : ($profile ? (int) ($profile['is_gateway'] ?? 0) : 0);
        $gatewaySubnet = trim($body['gateway_subnet'] ?? '');
        $customAllowed = trim($body['custom_allowed_ips'] ?? '');
        $hostname      = strtolower(trim($body['hostname'] ?? ''));
        $dnsAlias      = strtolower(trim($body['dns_alias'] ?? ''));

        if ($profile) {
            $dnsServer     = $dnsServer ?: '';
            $isGateway     = isset($body['is_gateway']) ? 1 : (int) ($profile['is_gateway'] ?? 0);
            $gatewaySubnet = $gatewaySubnet ?: '';
            $customAllowed = $customAllowed ?: '';
        }

        if (!$name || !$zoneId) {
            $_SESSION['flash_error'] = 'Name and zone are required.';
            return $response->withHeader('Location', '/peers')->withStatus(302);
        }

        try {
            $keys  = $this->wg->generateKeys();
            $vpnIp = $this->wg->allocateIp($zoneId);

            $this->db->execute("
                INSERT INTO peers (name, zone_id, profile_id, vpn_ip, public_key, private_key, preshared_key, dns, notes, is_gateway, gateway_subnet, custom_allowed_ips, hostname, dns_alias, enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ", [
                $name, $zoneId, $profileId, $vpnIp,
                $keys['public'], $keys['private'], $keys['preshared'],
                $dnsServer ?: null, $notes ?: null,
                $isGateway, $gatewaySubnet ?: null, $customAllowed ?: null,
                $hostname ?: null, $dnsAlias ?: null,
            ]);

            $this->wg->syncConfig();
            try { $this->dns->generateHostsFile(); } catch (\Throwable) {}
            $_SESSION['flash_success'] = "Peer \"{$name}\" created.";
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Error creating peer: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/peers')->withStatus(302);
    }

    public function update(Request $request, Response $response, string $id): Response
    {
        $body          = (array) $request->getParsedBody();
        $name          = trim($body['name'] ?? '');
        $profileId     = ($body['profile_id'] ?? '') !== '' ? (int) $body['profile_id'] : null;
        $dnsServer     = trim($body['dns'] ?? '');
        $notes         = trim($body['notes'] ?? '');
        $isGateway     = isset($body['is_gateway']) ? 1 : 0;
        $gatewaySubnet = trim($body['gateway_subnet'] ?? '');
        $customAllowed = trim($body['custom_allowed_ips'] ?? '');
        $hostname      = strtolower(trim($body['hostname'] ?? ''));
        $dnsAlias      = strtolower(trim($body['dns_alias'] ?? ''));
        $enabled       = isset($body['enabled']) ? 1 : 0;

        if (!$name) {
            $_SESSION['flash_error'] = 'Name is required.';
            return $response->withHeader('Location', '/peers')->withStatus(302);
        }

        try {
            $this->db->execute("
                UPDATE peers SET name = ?, profile_id = ?, dns = ?, notes = ?, is_gateway = ?, gateway_subnet = ?, custom_allowed_ips = ?, hostname = ?, dns_alias = ?, enabled = ?
                WHERE id = ?
            ", [
                $name, $profileId, $dnsServer ?: null, $notes ?: null,
                $isGateway, $gatewaySubnet ?: null, $customAllowed ?: null,
                $hostname ?: null, $dnsAlias ?: null,
                $enabled, $id,
            ]);

            $this->wg->syncConfig();
            try { $this->dns->generateHostsFile(); } catch (\Throwable) {}
            $_SESSION['flash_success'] = "Peer updated.";
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Error updating peer: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/peers')->withStatus(302);
    }

    public function destroy(Request $request, Response $response, string $id): Response
    {
        $peer = $this->db->queryOne("SELECT * FROM peers WHERE id = ?", [$id]);
        if ($peer) {
            $this->db->execute("DELETE FROM peers WHERE id = ?", [$id]);
            $this->wg->syncConfig();
            $_SESSION['flash_success'] = "Peer \"{$peer['name']}\" deleted.";
        }
        return $response->withHeader('Location', '/peers')->withStatus(302);
    }

    public function downloadConfig(Request $request, Response $response, string $id): Response
    {
        $peer = $this->db->queryOne("SELECT * FROM peers WHERE id = ?", [$id]);
        if (!$peer) {
            return $response->withStatus(404);
        }

        $mode     = $request->getQueryParams()['mode'] ?? 'wan';
        $conf     = $this->wg->generateClientConfig($id, $mode);
        $suffix   = $mode === 'lan' ? '-lan' : '';
        $filename = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $peer['name'])) . $suffix . '.conf';

        $response->getBody()->write($conf);
        return $response
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function createToken(Request $request, Response $response, string $id): Response
    {
        $peer = $this->db->queryOne("SELECT * FROM peers WHERE id = ?", [$id]);
        if (!$peer) {
            return $response->withStatus(404);
        }

        $body    = (array) $request->getParsedBody();
        $expires = trim($body['expires'] ?? '');
        $mode    = in_array($body['mode'] ?? '', ['lan', 'wan']) ? $body['mode'] : 'wan';

        $token = bin2hex(random_bytes(32));
        $expiresAt = match ($expires) {
            '1h'  => date('Y-m-d H:i:s', strtotime('+1 hour')),
            '24h' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            '7d'  => date('Y-m-d H:i:s', strtotime('+7 days')),
            default => null,
        };

        $this->db->execute(
            "INSERT INTO download_tokens (peer_id, token, expires_at) VALUES (?, ?, ?)",
            [$id, $token, $expiresAt]
        );

        $uri    = $request->getUri();
        $scheme = $request->getHeaderLine('X-Forwarded-Proto') ?: $uri->getScheme();
        $host   = $request->getHeaderLine('X-Forwarded-Host')  ?: $uri->getHost();
        $host   = explode(',', $host)[0];
        $appUrl = rtrim($scheme . '://' . trim($host), '/');
        $link   = $appUrl . '/dl/' . $token . ($mode === 'lan' ? '?mode=lan' : '');

        $data = ['url' => $link, 'token' => $token, 'expires_at' => $expiresAt];
        $response->getBody()->write((string) json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function downloadViaToken(Request $request, Response $response, string $token): Response
    {
        $row = $this->db->queryOne("SELECT * FROM download_tokens WHERE token = ?", [$token]);
        if (!$row) return $response->withStatus(404);

        if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
            $this->db->execute("DELETE FROM download_tokens WHERE token = ?", [$token]);
            $response->getBody()->write('Link expired.');
            return $response->withStatus(410);
        }

        $peer = $this->db->queryOne("SELECT * FROM peers WHERE id = ?", [$row['peer_id']]);
        if (!$peer) return $response->withStatus(404);

        $mode     = $request->getQueryParams()['mode'] ?? 'wan';
        $conf     = $this->wg->generateClientConfig((int) $peer['id'], $mode);
        $suffix   = $mode === 'lan' ? '-lan' : '';
        $filename = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $peer['name'])) . $suffix . '.conf';

        $response->getBody()->write($conf);
        return $response
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function qrCode(Request $request, Response $response, string $id): Response
    {
        $peer = $this->db->queryOne("SELECT * FROM peers WHERE id = ?", [$id]);
        if (!$peer) return $response->withStatus(404);

        $mode = $request->getQueryParams()['mode'] ?? 'wan';
        $conf = $this->wg->generateClientConfig($id, $mode);
        $encoded = base64_encode($conf);

        $response->getBody()->write((string) json_encode(['config_b64' => $encoded]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function checkSubnet(Request $request, Response $response): Response
    {
        $params    = $request->getQueryParams();
        $subnets   = array_filter(array_map('trim', explode(',', $params['subnets'] ?? '')));
        $excludeId = isset($params['exclude_id']) && $params['exclude_id'] !== ''
            ? (int) $params['exclude_id']
            : null;

        if (empty($subnets)) {
            $response->getBody()->write(json_encode(['conflicts' => []]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $sql  = "
            SELECT p.id, p.name, p.vpn_ip,
                   COALESCE(p.gateway_subnet, pr.gateway_subnet) AS gateway_subnet
            FROM peers p
            LEFT JOIN profiles pr ON pr.id = p.profile_id
            WHERE (p.is_gateway = 1 OR COALESCE(p.is_gateway, pr.is_gateway, 0) = 1)
              AND p.enabled = 1
              AND COALESCE(p.gateway_subnet, pr.gateway_subnet) IS NOT NULL
        ";
        $args = [];
        if ($excludeId !== null) {
            $sql   .= " AND p.id != ?";
            $args[] = $excludeId;
        }
        $existingPeers = $this->db->query($sql, $args);

        $conflicts = [];
        foreach ($existingPeers as $peer) {
            $existing = array_filter(array_map('trim', explode(',', $peer['gateway_subnet'])));
            foreach ($subnets as $newSubnet) {
                foreach ($existing as $existingSubnet) {
                    if ($this->subnetsOverlap($newSubnet, $existingSubnet)) {
                        $conflicts[] = [
                            'peer_name'          => $peer['name'],
                            'peer_vpn_ip'        => $peer['vpn_ip'],
                            'new_subnet'         => $newSubnet,
                            'conflicting_subnet' => $existingSubnet,
                        ];
                        break;
                    }
                }
            }
        }

        $response->getBody()->write(json_encode(['conflicts' => $conflicts]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function subnetsOverlap(string $subnetA, string $subnetB): bool
    {
        $subnetA = trim($subnetA);
        $subnetB = trim($subnetB);

        if (!str_contains($subnetA, '/') || !str_contains($subnetB, '/')) {
            return false;
        }

        [$netA, $prefA] = explode('/', $subnetA, 2);
        [$netB, $prefB] = explode('/', $subnetB, 2);

        $ipA = ip2long($netA);
        $ipB = ip2long($netB);

        if ($ipA === false || $ipB === false) return false;

        $minPrefix = min((int) $prefA, (int) $prefB);
        $shift     = 32 - $minPrefix;

        return (($ipA >> $shift) === ($ipB >> $shift));
    }
}
