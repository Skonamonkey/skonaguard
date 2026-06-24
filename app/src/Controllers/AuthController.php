<?php

declare(strict_types=1);

namespace SkonaGuard\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SkonaGuard\Models\Database;

class AuthController
{
    public function __construct(
        private Twig $view,
        private Database $db
    ) {}

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
        $body     = (array) $request->getParsedBody();
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        $user = $this->db->queryOne(
            "SELECT * FROM users WHERE username = ?",
            [$username]
        );

        if (!$user || !password_verify($password, $user['password'])) {
            $_SESSION['login_error'] = 'Invalid username or password.';
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        unset($_SESSION['login_error']);
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

        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        session_destroy();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
