<?php

declare(strict_types=1);

namespace SkonaGuard\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SkonaGuard\Models\Database;

class UsersController
{
    public function __construct(
        private Twig $view,
        private Database $db
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $users = $this->db->query("SELECT u.id, u.username, u.display_name, u.role, u.created_at, (u.totp_secret IS NOT NULL AND u.totp_secret != '') as has_2fa, GROUP_CONCAT(uz.zone_id) as zone_ids FROM users u LEFT JOIN user_zones uz ON uz.user_id = u.id GROUP BY u.id ORDER BY u.created_at");
        $zones = $this->db->query("SELECT * FROM zones WHERE is_system = 0 ORDER BY name");

        return $this->view->render($response, 'users/index.twig', [
            'active_nav' => 'users',
            'users'      => $users,
            'zones'      => $zones,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body        = (array) $request->getParsedBody();
        $username    = trim($body['username'] ?? '');
        $displayName = trim($body['display_name'] ?? '');
        $password    = $body['password'] ?? '';
        $role        = in_array($body['role'] ?? '', ['superadmin', 'admin', 'zone_admin']) ? $body['role'] : 'admin';
        $zoneIds     = array_filter(array_map('intval', (array) ($body['zone_ids'] ?? [])));

        if (!$username || !$password) {
            $_SESSION['flash_error'] = 'Username and password are required.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        if (strlen($password) < 8) {
            $_SESSION['flash_error'] = 'Password must be at least 8 characters.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        try {
            $this->db->execute(
                "INSERT INTO users (username, display_name, password, role) VALUES (?, ?, ?, ?)",
                [$username, $displayName ?: null, password_hash($password, PASSWORD_DEFAULT), $role]
            );
            $userId = $this->db->lastInsertId();

            if ($role === 'zone_admin' && $zoneIds) {
                foreach ($zoneIds as $zid) {
                    try {
                        $this->db->execute("INSERT OR IGNORE INTO user_zones (user_id, zone_id) VALUES (?, ?)", [$userId, $zid]);
                    } catch (\Throwable) {}
                }
            }

            $_SESSION['flash_success'] = "User \"{$username}\" created.";
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = str_contains($e->getMessage(), 'UNIQUE') ? "Username \"{$username}\" already exists." : 'Error creating user.';
        }

        return $response->withHeader('Location', '/users')->withStatus(302);
    }

    public function update(Request $request, Response $response, string $id): Response
    {
        $body        = (array) $request->getParsedBody();
        $userId      = (int) $id;
        $username    = trim($body['username'] ?? '');
        $displayName = trim($body['display_name'] ?? '');
        $password    = $body['password'] ?? '';
        $role        = in_array($body['role'] ?? '', ['superadmin', 'admin', 'zone_admin']) ? $body['role'] : 'admin';
        $zoneIds     = array_filter(array_map('intval', (array) ($body['zone_ids'] ?? [])));

        if (!$username) {
            $_SESSION['flash_error'] = 'Username is required.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $existing = $this->db->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$existing) {
            $_SESSION['flash_error'] = 'User not found.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        if ((int) $existing['id'] === (int) $_SESSION['user_id'] && $role !== 'superadmin') {
            $_SESSION['flash_error'] = 'You cannot remove your own superadmin role.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        try {
            if ($password) {
                if (strlen($password) < 8) {
                    $_SESSION['flash_error'] = 'Password must be at least 8 characters.';
                    return $response->withHeader('Location', '/users')->withStatus(302);
                }
                $this->db->execute(
                    "UPDATE users SET username = ?, display_name = ?, password = ?, role = ? WHERE id = ?",
                    [$username, $displayName ?: null, password_hash($password, PASSWORD_DEFAULT), $role, $userId]
                );
            } else {
                $this->db->execute(
                    "UPDATE users SET username = ?, display_name = ?, role = ? WHERE id = ?",
                    [$username, $displayName ?: null, $role, $userId]
                );
            }

            $this->db->execute("DELETE FROM user_zones WHERE user_id = ?", [$userId]);
            if ($role === 'zone_admin' && $zoneIds) {
                foreach ($zoneIds as $zid) {
                    try {
                        $this->db->execute("INSERT OR IGNORE INTO user_zones (user_id, zone_id) VALUES (?, ?)", [$userId, $zid]);
                    } catch (\Throwable) {}
                }
            }

            $_SESSION['flash_success'] = "User \"{$username}\" updated.";
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = str_contains($e->getMessage(), 'UNIQUE') ? "Username \"{$username}\" already exists." : 'Error updating user.';
        }

        return $response->withHeader('Location', '/users')->withStatus(302);
    }

    public function reset2fa(Request $request, Response $response, string $id): Response
    {
        $userId = (int) $id;
        $user   = $this->db->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);

        if (!$user) {
            $_SESSION['flash_error'] = 'User not found.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $this->db->execute("UPDATE users SET totp_secret = NULL WHERE id = ?", [$userId]);
        $_SESSION['flash_success'] = "2FA reset for \"{$user['username']}\". They will be prompted to set it up on next login (if 2FA is required globally).";
        return $response->withHeader('Location', '/users')->withStatus(302);
    }

    public function destroy(Request $request, Response $response, string $id): Response
    {
        $userId = (int) $id;

        if ($userId === (int) $_SESSION['user_id']) {
            $_SESSION['flash_error'] = 'You cannot delete your own account.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $user = $this->db->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if ($user) {
            $this->db->execute("DELETE FROM users WHERE id = ?", [$userId]);
            $_SESSION['flash_success'] = "User \"{$user['username']}\" deleted.";
        }

        return $response->withHeader('Location', '/users')->withStatus(302);
    }
}
