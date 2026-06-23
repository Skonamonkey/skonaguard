<?php

declare(strict_types=1);

use Slim\Views\Twig;
use SkonaGuard\Controllers\AuthController;
use SkonaGuard\Controllers\SetupController;
use SkonaGuard\Controllers\DashboardController;
use SkonaGuard\Middleware\AuthMiddleware;
use SkonaGuard\Middleware\SetupMiddleware;

$app->add(\Slim\Views\TwigMiddleware::createFromContainer($app, Twig::class));

$app->get('/', function ($req, $res) {
    $setupDone = ($_ENV['SETUP_COMPLETE'] ?? 'false') === 'true';
    if (!$setupDone) {
        return $res->withHeader('Location', '/setup')->withStatus(302);
    }
    return $res->withHeader('Location', '/dashboard')->withStatus(302);
});

$app->get('/setup[/{step}]', [SetupController::class, 'show']);
$app->post('/setup[/{step}]', [SetupController::class, 'handle']);

$app->get('/login', [AuthController::class, 'showLogin']);
$app->post('/login', [AuthController::class, 'login']);
$app->get('/logout', [AuthController::class, 'logout']);

$app->group('', function ($group) {
    $group->get('/dashboard', [DashboardController::class, 'index']);
})->add(AuthMiddleware::class);
