<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SkonaGuard\Controllers\AuthController;
use SkonaGuard\Controllers\SetupController;
use SkonaGuard\Controllers\DashboardController;
use SkonaGuard\Middleware\AuthMiddleware;

$app->add(\Slim\Views\TwigMiddleware::createFromContainer($app, Twig::class));

$app->get('/', function (Request $request, Response $response) {
    $setupDone = ($_ENV['SETUP_COMPLETE'] ?? 'false') === 'true';
    if (!$setupDone) {
        return $response->withHeader('Location', '/setup')->withStatus(302);
    }
    return $response->withHeader('Location', '/dashboard')->withStatus(302);
});

$app->get('/setup[/{step}]', [SetupController::class, 'show']);
$app->post('/setup[/{step}]', [SetupController::class, 'handle']);

$app->get('/login', [AuthController::class, 'showLogin']);
$app->post('/login', [AuthController::class, 'login']);
$app->get('/logout', [AuthController::class, 'logout']);

$app->group('', function (\Slim\Routing\RouteCollectorProxy $group) {
    $group->get('/dashboard', [DashboardController::class, 'index']);
})->add(AuthMiddleware::class);
