<?php

declare(strict_types=1);

namespace SkonaGuard\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SkonaGuard\Models\Database;
use SkonaGuard\Services\WireGuardService;

class SettingsController
{
    public function __construct(
        private Twig $view,
        private Database $db,
        private WireGuardService $wg
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $settings = [];
        foreach ($this->db->query("SELECT key, value FROM settings") as $row) {
            $settings[$row['key']] = $row['value'];
        }

        return $this->view->render($response, 'settings/index.twig', [
            'active_nav' => 'settings',
            'settings'   => $settings,
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $action = $body['action'] ?? 'server';

        if ($action === 'password') {
            return $this->changePassword($response, $body);
        }

        $serverIp = trim($body['server_public_ip'] ?? '');
        $wgPort   = trim($body['wg_port'] ?? '51820');
        $wgSubnet = trim($body['wg_subnet'] ?? '');

        if ($serverIp) {
            $this->db->execute("INSERT OR REPLACE INTO settings (key, value) VALUES ('server_public_ip', ?)", [$serverIp]);
        }
        if ($wgPort) {
            $this->db->execute("INSERT OR REPLACE INTO settings (key, value) VALUES ('wg_port', ?)", [$wgPort]);
        }
        if ($wgSubnet) {
            $this->db->execute("INSERT OR REPLACE INTO settings (key, value) VALUES ('wg_subnet', ?)", [$wgSubnet]);
        }

        try {
            $this->wg->syncConfig();
        } catch (\Throwable) {}

        $_SESSION['flash_success'] = 'Settings saved.';
        return $response->withHeader('Location', '/settings')->withStatus(302);
    }

    private function changePassword(Response $response, array $body): Response
    {
        $current  = $body['current_password'] ?? '';
        $new      = $body['new_password'] ?? '';
        $confirm  = $body['confirm_password'] ?? '';

        $user = $this->db->queryOne("SELECT * FROM users WHERE username = ?", [$_SESSION['username']]);
        if (!$user || !password_verify($current, $user['password'])) {
            $_SESSION['flash_error'] = 'Current password is incorrect.';
            return $response->withHeader('Location', '/settings')->withStatus(302);
        }

        if (strlen($new) < 8) {
            $_SESSION['flash_error'] = 'New password must be at least 8 characters.';
            return $response->withHeader('Location', '/settings')->withStatus(302);
        }

        if ($new !== $confirm) {
            $_SESSION['flash_error'] = 'Passwords do not match.';
            return $response->withHeader('Location', '/settings')->withStatus(302);
        }

        $this->db->execute("UPDATE users SET password = ? WHERE id = ?", [password_hash($new, PASSWORD_BCRYPT), $user['id']]);
        $_SESSION['flash_success'] = 'Password changed successfully.';
        return $response->withHeader('Location', '/settings')->withStatus(302);
    }
}
