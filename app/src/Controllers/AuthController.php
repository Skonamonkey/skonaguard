<?php

declare(strict_types=1);

namespace SkonaGuard\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RobThree\Auth\TwoFactorAuth;
use Slim\Views\Twig;
use SkonaGuard\Models\Database;

class AuthController
{
    private TwoFactorAuth $tfa;

    public function __construct(
        private Twig $view,
        private Database $db
    ) {
        $this->tfa = new TwoFactorAuth('SkonaGuard');
    }

    private const MAX_ATTEMPTS    = 5;
    private const LOCKOUT_MINUTES = 15;

    private function clientIp(Request $request): string
    {
        $params = $request->getServerParams();
        return $params['HTTP_X_FORWARDED_FOR']
            ? explode(',', $params['HTTP_X_FORWARDED_FOR'])[0]
            : ($params['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private function isLockedOut(string $ip): bool
    {
        $count = (int) ($this->db->queryOne(
            "SELECT COUNT(*) as c FROM login_attempts
             WHERE ip_address = ? AND attempted_at >= datetime('now', ? || ' minutes')",
            [$ip, '-' . self::LOCKOUT_MINUTES]
        )['c'] ?? 0);
        return $count >= self::MAX_ATTEMPTS;
    }

    private function minutesUntilUnlock(string $ip): int
    {
        $oldest = $this->db->queryOne(
            "SELECT attempted_at FROM login_attempts
             WHERE ip_address = ? AND attempted_at >= datetime('now', ? || ' minutes')
             ORDER BY attempted_at ASC LIMIT 1",
            [$ip, '-' . self::LOCKOUT_MINUTES]
        )['attempted_at'] ?? null;
        if (!$oldest) return 0;
        $unlockAt = strtotime($oldest) + (self::LOCKOUT_MINUTES * 60);
        return (int) max(1, ceil(($unlockAt - time()) / 60));
    }

    private function recordFailedAttempt(string $ip): void
    {
        $this->db->execute(
            "INSERT INTO login_attempts (ip_address) VALUES (?)",
            [$ip]
        );
        $this->db->execute(
            "DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-1 hour')"
        );
    }

    private function clearAttempts(string $ip): void
    {
        $this->db->execute("DELETE FROM login_attempts WHERE ip_address = ?", [$ip]);
    }

    public function showLogin(Request $request, Response $response): Response
    {
        if (!empty($_SESSION['user_id'])) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        return $this->view->render($response, 'auth/login.twig', [
            'error' => $_SESSION['login_error'] ?? null,
        ]);
    }

    public function login(Request $request, Response $response): Response
    {
        $ip = $this->clientIp($request);

        if ($this->isLockedOut($ip)) {
            $mins = $this->minutesUntilUnlock($ip);
            $_SESSION['login_error'] = "Too many failed attempts. Try again in {$mins} minute" . ($mins !== 1 ? 's' : '') . ".";
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $body     = (array) $request->getParsedBody();
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        $user = $this->db->queryOne(
            "SELECT * FROM users WHERE username = ?",
            [$username]
        );

        if (!$user || !password_verify($password, $user['password'])) {
            $this->recordFailedAttempt($ip);
            $_SESSION['login_error'] = 'Invalid username or password.';
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $this->clearAttempts($ip);
        unset($_SESSION['login_error']);

        $require2fa = ($this->db->queryOne("SELECT value FROM settings WHERE key = 'require_2fa'")['value'] ?? '0') === '1';
        $has2fa     = !empty($user['totp_secret']);

        if ($has2fa || $require2fa) {
            if (!$has2fa && $require2fa) {
                $_SESSION['pending_2fa_setup'] = [
                    'user_id'      => $user['id'],
                    'username'     => $user['username'],
                    'display_name' => $user['display_name'] ?? null,
                    'role'         => $user['role'] ?? 'superadmin',
                ];
                return $response->withHeader('Location', '/2fa/setup')->withStatus(302);
            }

            $_SESSION['pending_2fa'] = [
                'user_id'      => $user['id'],
                'username'     => $user['username'],
                'display_name' => $user['display_name'] ?? null,
                'role'         => $user['role'] ?? 'superadmin',
            ];
            return $response->withHeader('Location', '/2fa/verify')->withStatus(302);
        }

        $this->completeLogin($user);
        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }

    public function show2faVerify(Request $request, Response $response): Response
    {
        if (empty($_SESSION['pending_2fa'])) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        return $this->view->render($response, 'auth/2fa-verify.twig', [
            'error' => $_SESSION['2fa_error'] ?? null,
        ]);
    }

    public function verify2fa(Request $request, Response $response): Response
    {
        if (empty($_SESSION['pending_2fa'])) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $ip = $this->clientIp($request);

        if ($this->isLockedOut($ip)) {
            $mins = $this->minutesUntilUnlock($ip);
            unset($_SESSION['pending_2fa']);
            $_SESSION['login_error'] = "Too many failed attempts. Try again in {$mins} minute" . ($mins !== 1 ? 's' : '') . ".";
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $body = (array) $request->getParsedBody();
        $code = preg_replace('/\s+/', '', $body['totp_code'] ?? '');

        $userId = $_SESSION['pending_2fa']['user_id'];
        $user   = $this->db->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);

        if (!$user || !$this->tfa->verifyCode($user['totp_secret'], $code)) {
            $this->recordFailedAttempt($ip);
            $_SESSION['2fa_error'] = 'Invalid code. Please try again.';
            return $response->withHeader('Location', '/2fa/verify')->withStatus(302);
        }

        $this->clearAttempts($ip);
        unset($_SESSION['2fa_error']);
        $this->completeLogin($user);
        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }

    public function show2faSetup(Request $request, Response $response): Response
    {
        if (empty($_SESSION['pending_2fa_setup']) && !empty($_SESSION['user_id'])) {
            $user = $this->db->queryOne("SELECT * FROM users WHERE id = ?", [(int) $_SESSION['user_id']]);
            if ($user) {
                $_SESSION['pending_2fa_setup'] = [
                    'user_id'      => $user['id'],
                    'username'     => $user['username'],
                    'display_name' => $user['display_name'] ?? null,
                    'role'         => $user['role'] ?? 'superadmin',
                    'voluntary'    => true,
                ];
            }
        }

        $pending = $_SESSION['pending_2fa_setup'] ?? null;
        if (!$pending) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        if (empty($_SESSION['totp_pending_secret'])) {
            $_SESSION['totp_pending_secret'] = $this->tfa->createSecret();
        }

        $secret     = $_SESSION['totp_pending_secret'];
        $otpauthUri = $this->tfa->getQRText($pending['username'], $secret);

        return $this->view->render($response, 'auth/2fa-setup.twig', [
            'secret'      => $secret,
            'otpauth_uri' => $otpauthUri,
            'error'       => $_SESSION['2fa_setup_error'] ?? null,
            'voluntary'   => !empty($pending['voluntary']),
        ]);
    }

    public function confirm2faSetup(Request $request, Response $response): Response
    {
        $pending = $_SESSION['pending_2fa_setup'] ?? null;
        $secret  = $_SESSION['totp_pending_secret'] ?? null;

        if (!$pending || !$secret) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $body = (array) $request->getParsedBody();
        $code = preg_replace('/\s+/', '', $body['totp_code'] ?? '');

        if (!$this->tfa->verifyCode($secret, $code)) {
            $_SESSION['2fa_setup_error'] = 'Invalid code. Please scan the QR again and enter the correct code.';
            return $response->withHeader('Location', '/2fa/setup')->withStatus(302);
        }

        $this->db->execute("UPDATE users SET totp_secret = ? WHERE id = ?", [$secret, $pending['user_id']]);

        $wasVoluntary = !empty($pending['voluntary']);
        unset($_SESSION['2fa_setup_error'], $_SESSION['totp_pending_secret'], $_SESSION['pending_2fa_setup']);

        if ($wasVoluntary) {
            $_SESSION['flash_success'] = 'Two-factor authentication enabled.';
            return $response->withHeader('Location', '/settings')->withStatus(302);
        }

        $user = $this->db->queryOne("SELECT * FROM users WHERE id = ?", [$pending['user_id']]);
        $this->completeLogin($user);
        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }

    public function disable2fa(Request $request, Response $response): Response
    {
        if (empty($_SESSION['user_id'])) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $this->db->execute("UPDATE users SET totp_secret = NULL WHERE id = ?", [(int) $_SESSION['user_id']]);
        $_SESSION['flash_success'] = 'Two-factor authentication disabled.';
        return $response->withHeader('Location', '/settings')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        session_destroy();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    private function completeLogin(array $user): void
    {
        unset($_SESSION['pending_2fa'], $_SESSION['pending_2fa_setup'], $_SESSION['totp_pending_secret']);
        $_SESSION['user_id']      = $user['id'];
        $_SESSION['username']     = $user['username'];
        $_SESSION['display_name'] = $user['display_name'] ?? null;
        $_SESSION['role']         = $user['role'] ?? 'superadmin';

        if ($_SESSION['role'] === 'zone_admin') {
            $rows = $this->db->query("SELECT zone_id FROM user_zones WHERE user_id = ?", [(int) $user['id']]);
            $_SESSION['zone_ids'] = array_column($rows, 'zone_id');
        } else {
            $_SESSION['zone_ids'] = null;
        }
    }
}
