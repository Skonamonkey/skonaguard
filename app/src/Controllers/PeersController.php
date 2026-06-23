<?php

declare(strict_types=1);

namespace SkonaGuard\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SkonaGuard\Models\Database;
use SkonaGuard\Services\WireGuardService;

class PeersController
{
    public function __construct(
        private Twig $view,
        private Database $db,
        private WireGuardService $wg
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
        $body    = (array) $request->getParsedBody();
        $name    = trim($body['name'] ?? '');
        $zoneId  = (int) ($body['zone_id'] ?? 0);
        $profileId = ($body['profile_id'] ?? '') !== '' ? (int) $body['profile_id'] : null;
        $dns     = trim($body['dns'] ?? '');
        $notes   = trim($body['notes'] ?? '');
        $isGateway     = isset($body['is_gateway']) ? 1 : 0;
        $gatewaySubnet = trim($body['gateway_subnet'] ?? '');
        $customAllowed = trim($body['custom_allowed_ips'] ?? '');

        if (!$name || !$zoneId) {
            $_SESSION['flash_error'] = 'Name and zone are required.';
            return $response->withHeader('Location', '/peers')->withStatus(302);
        }

        try {
            $keys  = $this->wg->generateKeys();
            $vpnIp = $this->wg->allocateIp($zoneId);

            $this->db->execute("
                INSERT INTO peers (name, zone_id, profile_id, vpn_ip, public_key, private_key, preshared_key, dns, notes, is_gateway, gateway_subnet, custom_allowed_ips, enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ", [
                $name, $zoneId, $profileId, $vpnIp,
                $keys['public'], $keys['private'], $keys['preshared'],
                $dns ?: null, $notes ?: null,
                $isGateway, $gatewaySubnet ?: null, $customAllowed ?: null,
            ]);

            $this->wg->syncConfig();
            $_SESSION['flash_success'] = "Peer \"{$name}\" created.";
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Error creating peer: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/peers')->withStatus(302);
    }

    public function update(Request $request, Response $response, string $id): Response
    {
        $body   = (array) $request->getParsedBody();
        $name   = trim($body['name'] ?? '');
        $profileId     = ($body['profile_id'] ?? '') !== '' ? (int) $body['profile_id'] : null;
        $dns           = trim($body['dns'] ?? '');
        $notes         = trim($body['notes'] ?? '');
        $isGateway     = isset($body['is_gateway']) ? 1 : 0;
        $gatewaySubnet = trim($body['gateway_subnet'] ?? '');
        $customAllowed = trim($body['custom_allowed_ips'] ?? '');
        $enabled       = isset($body['enabled']) ? 1 : 0;

        if (!$name) {
            $_SESSION['flash_error'] = 'Name is required.';
            return $response->withHeader('Location', '/peers')->withStatus(302);
        }

        try {
            $this->db->execute("
                UPDATE peers SET name = ?, profile_id = ?, dns = ?, notes = ?, is_gateway = ?, gateway_subnet = ?, custom_allowed_ips = ?, enabled = ?
                WHERE id = ?
            ", [
                $name, $profileId, $dns ?: null, $notes ?: null,
                $isGateway, $gatewaySubnet ?: null, $customAllowed ?: null,
                $enabled, $id,
            ]);

            $this->wg->syncConfig();
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
}
