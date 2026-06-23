<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SkonaGuard\Controllers\AuthController;
use SkonaGuard\Controllers\SetupController;
use SkonaGuard\Controllers\DashboardController;
use SkonaGuard\Controllers\ZonesController;
use SkonaGuard\Controllers\PeersController;
use SkonaGuard\Controllers\ProfilesController;
use SkonaGuard\Controllers\SettingsController;
use SkonaGuard\Controllers\AclController;
use SkonaGuard\Middleware\AuthMiddleware;

$app->add(\Slim\Views\TwigMiddleware::createFromContainer($app, Twig::class));

$app->get('/', function (Request $request, Response $response) use ($app) {
    $db = $app->getContainer()->get(\SkonaGuard\Models\Database::class);
    $setupDone = (bool) $db->queryOne("SELECT COUNT(*) as c FROM users")['c'];
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

$app->get('/dl/{token}', [PeersController::class, 'downloadViaToken']);

$app->group('', function (\Slim\Routing\RouteCollectorProxy $group) {
    $group->get('/dashboard', [DashboardController::class, 'index']);
    $group->get('/dashboard/status', [DashboardController::class, 'status']);

    $group->get('/zones', [ZonesController::class, 'index']);
    $group->post('/zones', [ZonesController::class, 'store']);
    $group->post('/zones/{id:[0-9]+}', [ZonesController::class, 'update']);
    $group->post('/zones/{id:[0-9]+}/delete', [ZonesController::class, 'destroy']);

    $group->get('/peers', [PeersController::class, 'index']);
    $group->post('/peers', [PeersController::class, 'store']);
    $group->post('/peers/{id:[0-9]+}', [PeersController::class, 'update']);
    $group->post('/peers/{id:[0-9]+}/delete', [PeersController::class, 'destroy']);
    $group->get('/peers/{id:[0-9]+}/download', [PeersController::class, 'downloadConfig']);
    $group->post('/peers/{id:[0-9]+}/token', [PeersController::class, 'createToken']);
    $group->get('/peers/{id:[0-9]+}/qr', [PeersController::class, 'qrCode']);

    $group->get('/profiles', [ProfilesController::class, 'index']);
    $group->post('/profiles', [ProfilesController::class, 'store']);
    $group->post('/profiles/{id:[0-9]+}', [ProfilesController::class, 'update']);
    $group->post('/profiles/{id:[0-9]+}/delete', [ProfilesController::class, 'destroy']);

    $group->get('/settings', [SettingsController::class, 'index']);
    $group->post('/settings', [SettingsController::class, 'update']);

    $group->get('/acls', [AclController::class, 'index']);
    $group->get('/acls/chain', [AclController::class, 'chain']);
    $group->post('/acls', [AclController::class, 'store']);
    $group->post('/acls/{id:[0-9]+}', [AclController::class, 'update']);
    $group->post('/acls/{id:[0-9]+}/delete', [AclController::class, 'destroy']);
})->add(AuthMiddleware::class);
