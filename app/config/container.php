<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Views\Twig;
use SkonaGuard\Models\Database;
use SkonaGuard\Services\WireGuardService;

$builder = new ContainerBuilder();

$builder->addDefinitions([
    Twig::class => function () {
        $twig = Twig::create(APP_ROOT . '/templates', [
            'cache' => false,
            'debug' => $_ENV['APP_ENV'] !== 'production',
        ]);

        $twig->getEnvironment()->addGlobal('app_name', 'SkonaGuard');
        $twig->getEnvironment()->addGlobal('app_version', '2.0.0');
        $twig->getEnvironment()->addGlobal('session', $_SESSION);
        $twig->getEnvironment()->addGlobal('theme', $_COOKIE['theme'] ?? 'dark');

        return $twig;
    },

    Database::class => function () {
        return new Database($_ENV['DB_PATH'] ?? APP_ROOT . '/database/skonaguard.db');
    },

    WireGuardService::class => function (\Psr\Container\ContainerInterface $c) {
        return new WireGuardService($c->get(Database::class));
    },
]);

return $builder->build();
