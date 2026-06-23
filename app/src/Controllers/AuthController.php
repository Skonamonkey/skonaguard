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

    public function showLogin(Request $req, Response $res): Response
    {
        if (!empty($_SESSION['user_id'])) {
            return $res->withHeader('Location', '/dashboard')->withStatus(302);
        }

        return $this->view->render($res, 'auth/login.twig', [
            'error' => $_SESSION['login_error'] ?? null,
        ]);
    }

    public function login(Request $req, Response $res): Response
    {
        $body     = (array) $req->getParsedBody();
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        $user = $this->db->queryOne(
            "SELECT * FROM users WHERE username = ?",
            [$username]
        );

        if (!$user || !password_verify($password, $user['password'])) {
            $_SESSION['login_error'] = 'Invalid username or password.';
            return $res->withHeader('Location', '/login')->withStatus(302);
        }

        unset($_SESSION['login_error']);
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];

        return $res->withHeader('Location', '/dashboard')->withStatus(302);
    }

    public function logout(Request $req, Response $res): Response
    {
        session_destroy();
        return $res->withHeader('Location', '/login')->withStatus(302);
    }
}
