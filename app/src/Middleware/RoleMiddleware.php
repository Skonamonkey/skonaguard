<?php

declare(strict_types=1);

namespace SkonaGuard\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response as SlimResponse;

class RoleMiddleware implements MiddlewareInterface
{
    private const HIERARCHY = ['zone_admin' => 0, 'admin' => 1, 'superadmin' => 2];

    public function __construct(private string $minRole) {}

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $userRole  = $_SESSION['role'] ?? 'zone_admin';
        $userLevel = self::HIERARCHY[$userRole]  ?? 0;
        $minLevel  = self::HIERARCHY[$this->minRole] ?? 2;

        if ($userLevel < $minLevel) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        return $handler->handle($request);
    }

    public static function require(string $minRole): self
    {
        return new self($minRole);
    }
}
