<?php

declare(strict_types=1);

namespace SkonaGuard\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SkonaGuard\Models\Database;

class SetupController
{
    private const BLOCKED_USERNAMES = ['admin', 'root', 'administrator', 'superuser', 'sysadmin'];
    private const TOTAL_STEPS = 4;

    public function __construct(
        private Twig $view,
        private Database $db
    ) {}

    public function show(Request $request, Response $response, array $args): Response
    {
        if (($_ENV['SETUP_COMPLETE'] ?? 'false') === 'true') {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $step = (int) ($args['step'] ?? 1);
        return $this->view->render($response, 'setup/wizard.twig', [
            'step'        => $step,
            'total_steps' => self::TOTAL_STEPS,
            'error'       => $_SESSION['setup_error'] ?? null,
            'data'        => $_SESSION['setup_data'] ?? [],
            'detected_ip' => $this->detectPublicIp(),
        ]);
    }

    public function handle(Request $request, Response $response, array $args): Response
    {
        if (($_ENV['SETUP_COMPLETE'] ?? 'false') === 'true') {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $step = (int) ($args['step'] ?? 1);
        $body = (array) $request->getParsedBody();

        unset($_SESSION['setup_error']);

        return match ($step) {
            1 => $this->handleAccount($response, $body),
            2 => $this->handleServer($response, $body),
            3 => $this->handleFirstZone($response, $body),
            4 => $this->handleComplete($response),
            default => $response->withHeader('Location', '/setup/1')->withStatus(302),
        };
    }

    private function handleAccount(Response $response, array $body): Response
    {
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        $confirm  = $body['confirm'] ?? '';

        if (strlen($username) < 3) {
            return $this->error($response, 1, 'Username must be at least 3 characters.');
        }
        if (in_array(strtolower($username), self::BLOCKED_USERNAMES, true)) {
            return $this->error($response, 1, "\"$username\" is not allowed as a username. Please choose something unique.");
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username)) {
            return $this->error($response, 1, 'Username may only contain letters, numbers, underscores, hyphens and dots.');
        }
        if (strlen($password) < 10) {
            return $this->error($response, 1, 'Password must be at least 10 characters.');
        }
        if ($password !== $confirm) {
            return $this->error($response, 1, 'Passwords do not match.');
        }

        $_SESSION['setup_data']['username'] = $username;
        $_SESSION['setup_data']['password'] = $password;

        return $response->withHeader('Location', '/setup/2')->withStatus(302);
    }

    private function handleServer(Response $response, array $body): Response
    {
        $ip     = trim($body['server_ip'] ?? '');
        $port   = (int) ($body['wg_port'] ?? 51820);
        $subnet = trim($body['wg_subnet'] ?? '172.16.0.0/16');

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return $this->error($response, 2, 'Invalid server IP address.');
        }
        if ($port < 1 || $port > 65535) {
            return $this->error($response, 2, 'Port must be between 1 and 65535.');
        }
        if (!$this->isValidCidr($subnet)) {
            return $this->error($response, 2, 'Invalid subnet. Use CIDR format, e.g. 172.16.0.0/16');
        }

        $_SESSION['setup_data']['server_ip'] = $ip;
        $_SESSION['setup_data']['wg_port']   = $port;
        $_SESSION['setup_data']['wg_subnet']  = $subnet;

        return $response->withHeader('Location', '/setup/3')->withStatus(302);
    }

    private function handleFirstZone(Response $response, array $body): Response
    {
        $name        = trim($body['zone_name'] ?? '');
        $subnet      = trim($body['zone_subnet'] ?? '');
        $description = trim($body['zone_description'] ?? '');

        if (strlen($name) < 2) {
            return $this->error($response, 3, 'Zone name must be at least 2 characters.');
        }
        if (!$this->isValidCidr($subnet)) {
            return $this->error($response, 3, 'Invalid subnet. Use CIDR format, e.g. 172.16.1.0/24');
        }

        $_SESSION['setup_data']['zone_name']        = $name;
        $_SESSION['setup_data']['zone_subnet']       = $subnet;
        $_SESSION['setup_data']['zone_description']  = $description;

        return $response->withHeader('Location', '/setup/4')->withStatus(302);
    }

    private function handleComplete(Response $response): Response
    {
        $data = $_SESSION['setup_data'] ?? [];

        if (empty($data['username']) || empty($data['password'])) {
            return $response->withHeader('Location', '/setup/1')->withStatus(302);
        }

        $this->db->execute(
            "INSERT INTO users (username, password) VALUES (?, ?)",
            [$data['username'], password_hash($data['password'], PASSWORD_BCRYPT)]
        );

        $this->db->execute(
            "INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)",
            ['server_public_ip', $data['server_ip'] ?? '']
        );
        $this->db->execute(
            "INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)",
            ['wg_port', (string) ($data['wg_port'] ?? 51820)]
        );
        $this->db->execute(
            "INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)",
            ['wg_subnet', $data['wg_subnet'] ?? '172.16.0.0/16']
        );

        if (!empty($data['zone_name'])) {
            $this->db->execute(
                "INSERT INTO zones (name, subnet, description) VALUES (?, ?, ?)",
                [$data['zone_name'], $data['zone_subnet'], $data['zone_description'] ?? null]
            );
        }

        $envFile = APP_ROOT . '/../.env';
        if (file_exists($envFile)) {
            $env = file_get_contents($envFile);
            $env = preg_replace('/^SETUP_COMPLETE=.*/m', 'SETUP_COMPLETE=true', $env);
            file_put_contents($envFile, $env);
        }
        $_ENV['SETUP_COMPLETE'] = 'true';

        $_SESSION['setup_data'] = [];
        $_SESSION['user_id']    = $this->db->queryOne("SELECT id FROM users WHERE username = ?", [$data['username']])['id'];
        $_SESSION['username']   = $data['username'];

        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }

    private function error(Response $response, int $step, string $message): Response
    {
        $_SESSION['setup_error'] = $message;
        return $response->withHeader('Location', "/setup/$step")->withStatus(302);
    }

    private function detectPublicIp(): string
    {
        $ip = @file_get_contents('https://ifconfig.me');
        return filter_var(trim((string) $ip), FILTER_VALIDATE_IP) ? trim($ip) : '';
    }

    private function isValidCidr(string $cidr): bool
    {
        if (!str_contains($cidr, '/')) return false;
        [$ip, $prefix] = explode('/', $cidr, 2);
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && is_numeric($prefix)
            && (int) $prefix >= 0
            && (int) $prefix <= 32;
    }
}
