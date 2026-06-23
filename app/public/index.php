<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(APP_ROOT);
$dotenv->safeLoad();

session_start();

$container = require APP_ROOT . '/config/container.php';

$app = \DI\Bridge\Slim\Bridge::create($container);

$app->addErrorMiddleware(
    $_ENV['APP_ENV'] !== 'production',
    true,
    true
);

require APP_ROOT . '/src/Routes/web.php';

$app->run();
