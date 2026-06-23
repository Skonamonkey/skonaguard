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
            SELECT p.*, COUNT(DISTINCT pr.id) as peer_count
            FROM profiles p
            LEFT JOIN peers pr ON pr.profile_id = p.id
            GROUP BY p.id
            ORDER BY p.name
        ");

        $zones = $this->db->query("SELECT * FROM zones ORDER BY name");

        $profileZones = [];
        foreach ($profiles as $profile) {
            $profileZones[$profile['id']] = $this->db->query("
                SELECT z.*, pza.access_type
                FROM profile_zone_access pza
                JOIN zones z ON z.id = pza.zone_id
                WHERE pza.profile_id = ?
            ", [$profile['id']]);
        }

        return $this->view->render($response, 'profiles/index.twig', [
            'active_nav'   => 'profiles',
            'profiles'     => $profiles,
            'zones'        => $zones,
            'profileZones' => $profileZones,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $name = trim($body['name'] ?? '');
        $desc = trim($body['description'] ?? '');

        if (!$name) {
            $_SESSION['flash_error'] = 'Profile name is required.';
            return $response->withHeader('Location', '/profiles')->withStatus(302);
        }

        try {
            $this->db->execute("INSERT INTO profiles (name, description) VALUES (?, ?)", [$name, $desc ?: null]);
            $profileId = (int) $this->db->lastInsertId();
            $this->syncZoneAccess($profileId, $body);
            $_SESSION['flash_success'] = "Profile \"{$name}\" created.";
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'A profile with that name already exists.';
        }

        return $response->withHeader('Location', '/profiles')->withStatus(302);
    }

    public function update(Request $request, Response $response, string $id): Response
    {
        $body = (array) $request->getParsedBody();
        $name = trim($body['name'] ?? '');
        $desc = trim($body['description'] ?? '');

        if (!$name) {
            $_SESSION['flash_error'] = 'Profile name is required.';
            return $response->withHeader('Location', '/profiles')->withStatus(302);
        }

        try {
            $this->db->execute("UPDATE profiles SET name = ?, description = ? WHERE id = ?", [$name, $desc ?: null, $id]);
            $this->db->execute("DELETE FROM profile_zone_access WHERE profile_id = ?", [$id]);
            $this->syncZoneAccess($id, $body);
            $_SESSION['flash_success'] = "Profile updated.";
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'A profile with that name already exists.';
        }

        return $response->withHeader('Location', '/profiles')->withStatus(302);
    }

    public function destroy(Request $request, Response $response, string $id): Response
    {
        $profile = $this->db->queryOne("SELECT * FROM profiles WHERE id = ?", [$id]);
        if ($profile) {
            $this->db->execute("DELETE FROM profiles WHERE id = ?", [$id]);
            $_SESSION['flash_success'] = "Profile \"{$profile['name']}\" deleted.";
        }
        return $response->withHeader('Location', '/profiles')->withStatus(302);
    }

    private function syncZoneAccess(int $profileId, array $body): void
    {
        $zoneIds     = (array) ($body['zone_ids'] ?? []);
        $accessTypes = (array) ($body['access_types'] ?? []);

        foreach ($zoneIds as $zoneId) {
            $zoneId     = (int) $zoneId;
            $accessType = $accessTypes[$zoneId] ?? 'full';
            $this->db->execute(
                "INSERT OR REPLACE INTO profile_zone_access (profile_id, zone_id, access_type) VALUES (?, ?, ?)",
                [$profileId, $zoneId, $accessType]
            );
        }
    }
}
