<?php

declare(strict_types=1);

namespace SkonaGuard\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SkonaGuard\Models\Database;

class ProfilesController
{
    public function __construct(
        private Twig $view,
        private Database $db
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $profiles = $this->db->query("
            SELECT p.*, z.name as zone_name, z.subnet as zone_subnet, COUNT(DISTINCT pr.id) as peer_count
            FROM profiles p
            LEFT JOIN zones z ON z.id = p.zone_id
            LEFT JOIN peers pr ON pr.profile_id = p.id
            GROUP BY p.id
            ORDER BY p.name
        ");

        $zones = $this->db->query("SELECT * FROM zones ORDER BY name");

        return $this->view->render($response, 'profiles/index.twig', [
            'active_nav' => 'profiles',
            'profiles'   => $profiles,
            'zones'      => $zones,
        ]);
    }

    public function data(Request $request, Response $response, string $id): Response
    {
        $profile = $this->db->queryOne("
            SELECT p.*, z.name as zone_name, z.subnet as zone_subnet
            FROM profiles p
            LEFT JOIN zones z ON z.id = p.zone_id
            WHERE p.id = ?
        ", [(int) $id]);

        if (!$profile) {
            $response->getBody()->write(json_encode(['error' => 'Not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write(json_encode($profile));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $body          = (array) $request->getParsedBody();
        $name          = trim($body['name'] ?? '');
        $desc          = trim($body['description'] ?? '');
        $zoneId        = ($body['zone_id'] ?? '') !== '' ? (int) $body['zone_id'] : null;
        $allowedIps    = trim($body['custom_allowed_ips'] ?? '');
        $dns           = trim($body['dns'] ?? '');
        $isGateway     = isset($body['is_gateway']) ? 1 : 0;
        $gatewaySubnet = trim($body['gateway_subnet'] ?? '');

        if (!$name) {
            $_SESSION['flash_error'] = 'Profile name is required.';
            return $response->withHeader('Location', '/profiles')->withStatus(302);
        }

        try {
            $this->db->execute("
                INSERT INTO profiles (name, description, zone_id, custom_allowed_ips, dns, is_gateway, gateway_subnet)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ", [$name, $desc ?: null, $zoneId, $allowedIps ?: null, $dns ?: null, $isGateway, $gatewaySubnet ?: null]);
            $_SESSION['flash_success'] = "Profile \"{$name}\" created.";
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'A profile with that name already exists.';
        }

        return $response->withHeader('Location', '/profiles')->withStatus(302);
    }

    public function update(Request $request, Response $response, string $id): Response
    {
        $body          = (array) $request->getParsedBody();
        $name          = trim($body['name'] ?? '');
        $desc          = trim($body['description'] ?? '');
        $zoneId        = ($body['zone_id'] ?? '') !== '' ? (int) $body['zone_id'] : null;
        $allowedIps    = trim($body['custom_allowed_ips'] ?? '');
        $dns           = trim($body['dns'] ?? '');
        $isGateway     = isset($body['is_gateway']) ? 1 : 0;
        $gatewaySubnet = trim($body['gateway_subnet'] ?? '');

        if (!$name) {
            $_SESSION['flash_error'] = 'Profile name is required.';
            return $response->withHeader('Location', '/profiles')->withStatus(302);
        }

        try {
            $this->db->execute("
                UPDATE profiles SET name = ?, description = ?, zone_id = ?, custom_allowed_ips = ?, dns = ?, is_gateway = ?, gateway_subnet = ?
                WHERE id = ?
            ", [$name, $desc ?: null, $zoneId, $allowedIps ?: null, $dns ?: null, $isGateway, $gatewaySubnet ?: null, (int) $id]);
            $_SESSION['flash_success'] = "Profile updated.";
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'A profile with that name already exists.';
        }

        return $response->withHeader('Location', '/profiles')->withStatus(302);
    }

    public function destroy(Request $request, Response $response, string $id): Response
    {
        $profile = $this->db->queryOne("SELECT * FROM profiles WHERE id = ?", [(int) $id]);
        if ($profile) {
            $this->db->execute("DELETE FROM profiles WHERE id = ?", [(int) $id]);
            $_SESSION['flash_success'] = "Profile \"{$profile['name']}\" deleted.";
        }
        return $response->withHeader('Location', '/profiles')->withStatus(302);
    }
}
