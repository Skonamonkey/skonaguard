<?php

declare(strict_types=1);

namespace SkonaGuard\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SkonaGuard\Models\Database;
use SkonaGuard\Services\DnsService;
use SkonaGuard\Services\WireGuardService;

class SettingsController
{
    public function __construct(
        private Twig $view,
        private Database $db,
        private WireGuardService $wg,
        private DnsService $dns
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $settings = [];
        foreach ($this->db->query("SELECT key, value FROM settings") as $row) {
            $settings[$row['key']] = $row['value'];
        }

        $currentUser = $this->db->queryOne("SELECT totp_secret FROM users WHERE id = ?", [(int) $_SESSION['user_id']]);

        return $this->view->render($response, 'settings/index.twig', [
            'active_nav'    => 'settings',
            'settings'      => $settings,
            'user_has_2fa'  => !empty($currentUser['totp_secret']),
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $action = $body['action'] ?? 'server';

        if ($action === 'password') {
            return $this->changePassword($response, $body);
        }

        if ($action === 'acl') {
            return $this->updateAcl($response, $body);
        }

        if ($action === 'dns') {
            return $this->updateDns($response, $body);
        }

        if ($action === '2fa_global') {
            return $this->update2faGlobal($response, $body);
        }

        $serverIp  = trim($body['server_public_ip'] ?? '');
        $serverLan = trim($body['server_lan_ip'] ?? '');
        $wgPort    = trim($body['wg_port'] ?? '51820');
        $wgSubnet  = trim($body['wg_subnet'] ?? '');

        if ($serverIp) {
            $this->db->execute("INSERT OR REPLACE INTO settings (key, value) VALUES ('server_public_ip', ?)", [$serverIp]);
        }
        $this->db->execute("INSERT OR REPLACE INTO settings (key, value) VALUES ('server_lan_ip', ?)", [$serverLan]);
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

    private function updateAcl(Response $response, array $body): Response
    {
        $enabled       = isset($body['acl_enforcement']) ? '1' : '0';
        $defaultPolicy = ($body['acl_default_policy'] ?? 'permissive') === 'restrictive' ? 'restrictive' : 'permissive';

        $this->db->execute("INSERT OR REPLACE INTO settings (key, value) VALUES ('acl_enforcement', ?)", [$enabled]);
        $this->db->execute("INSERT OR REPLACE INTO settings (key, value) VALUES ('acl_default_policy', ?)", [$defaultPolicy]);

        try {
            $this->wg->syncAcl();
        } catch (\Throwable) {}

        $_SESSION['flash_success'] = 'ACL enforcement ' . ($enabled === '1' ? 'enabled' : 'disabled') . '.';
        return $response->withHeader('Location', '/settings')->withStatus(302);
    }

    private function updateDns(Response $response, array $body): Response
    {
        $enabled  = isset($body['dns_enabled']) ? '1' : '0';
        $domain   = trim($body['dns_domain'] ?? 'skona');
        $upstream = trim($body['dns_upstream'] ?? '9.9.9.9');

        $domain   = $domain   ?: 'skona';
        $upstream = $upstream ?: '9.9.9.9';

        $this->db->execute("INSERT OR REPLACE INTO settings (key, value) VALUES ('dns_enabled', ?)",  [$enabled]);
        $this->db->execute("INSERT OR REPLACE INTO settings (key, value) VALUES ('dns_domain', ?)",   [$domain]);
        $this->db->execute("INSERT OR REPLACE INTO settings (key, value) VALUES ('dns_upstream', ?)", [$upstream]);

        try {
            $this->dns->sync();
            if ($enabled === '1') {
                $this->wg->syncConfig();
            }
        } catch (\Throwable) {}

        $_SESSION['flash_success'] = 'DNS settings saved' . ($enabled === '1' ? ' — server restarting.' : ' — DNS disabled.');
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

    private function update2faGlobal(Response $response, array $body): Response
    {
        $require = isset($body['require_2fa']) ? '1' : '0';
        $this->db->execute("INSERT OR REPLACE INTO settings (key, value) VALUES ('require_2fa', ?)", [$require]);
        $_SESSION['flash_success'] = 'Two-factor authentication policy ' . ($require === '1' ? 'enabled — all users must set up 2FA on next login.' : 'disabled.');
        return $response->withHeader('Location', '/settings')->withStatus(302);
    }
}
