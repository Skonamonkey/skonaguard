<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$privateKey = $argv[1] ?? '';
$publicKey  = $argv[2] ?? '';

if (!$privateKey || !$publicKey) {
    exit("Usage: init_keys.php <private_key> <public_key>\n");
}

$dbPath = $_ENV['DB_PATH'] ?? APP_ROOT . '/database/skonaguard.db';
$pdo = new PDO('sqlite:' . $dbPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$set = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
$set->execute(['server_private_key', $privateKey]);
$set->execute(['server_public_key', $publicKey]);

echo "Keys stored.\n";
