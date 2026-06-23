<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$dbPath = $_ENV['DB_PATH'] ?? APP_ROOT . '/database/skonaguard.db';

if (!is_dir(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0755, true);
}

$pdo = new PDO('sqlite:' . $dbPath, options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('PRAGMA journal_mode=WAL');
$pdo->exec('PRAGMA foreign_keys=ON');

$pdo->exec("
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    username   TEXT    NOT NULL UNIQUE,
    password   TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS zones (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL UNIQUE,
    subnet      TEXT    NOT NULL UNIQUE,
    description TEXT,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS profiles (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL UNIQUE,
    description TEXT,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS profile_zone_access (
    profile_id  INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
    zone_id     INTEGER NOT NULL REFERENCES zones(id)    ON DELETE CASCADE,
    access_type TEXT    NOT NULL DEFAULT 'full',
    PRIMARY KEY (profile_id, zone_id)
);

CREATE TABLE IF NOT EXISTS peers (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    name             TEXT    NOT NULL,
    zone_id          INTEGER NOT NULL REFERENCES zones(id),
    profile_id       INTEGER          REFERENCES profiles(id),
    vpn_ip           TEXT    NOT NULL UNIQUE,
    public_key       TEXT    NOT NULL UNIQUE,
    private_key      TEXT    NOT NULL,
    preshared_key    TEXT    NOT NULL,
    dns              TEXT,
    notes            TEXT,
    is_gateway       INTEGER NOT NULL DEFAULT 0,
    gateway_subnet   TEXT,
    custom_allowed_ips TEXT,
    enabled          INTEGER NOT NULL DEFAULT 1,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS acl_rules (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT    NOT NULL,
    src_zone_id     INTEGER          REFERENCES zones(id) ON DELETE CASCADE,
    dst_zone_id     INTEGER          REFERENCES zones(id) ON DELETE CASCADE,
    src_ip_override TEXT,
    dst_ip_override TEXT,
    action          TEXT    NOT NULL DEFAULT 'ACCEPT',
    rule_type       TEXT    NOT NULL DEFAULT 'full',
    dst_port        TEXT,
    priority        INTEGER NOT NULL DEFAULT 100,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS download_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    peer_id    INTEGER NOT NULL REFERENCES peers(id) ON DELETE CASCADE,
    token      TEXT    NOT NULL UNIQUE,
    expires_at TEXT,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);
");

// Additive migrations for existing databases
$alterations = [
    "ALTER TABLE acl_rules ADD COLUMN dst_port TEXT",
];
foreach ($alterations as $sql) {
    try { $pdo->exec($sql); } catch (\Exception $e) { /* column already exists */ }
}

echo "Database migrated successfully.\n";
