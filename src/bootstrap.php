<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/config/config.php';

foreach ([$config['paths']['uploads'], $config['paths']['hls']] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Не удалось создать каталог: {$directory}");
    }
}

$pdo = new PDO(
    $config['database']['dsn'],
    $config['database']['user'] ?? null,
    $config['database']['password'] ?? null,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);

if (str_starts_with($config['database']['dsn'], 'sqlite:')) {
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
}

if (PHP_SAPI !== 'cli') {
    session_name($config['session_name']);
    session_set_cookie_params([
        'httponly' => true,
        'secure' => str_starts_with($config['base_url'], 'https://'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/worker.php';

ensure_schema($pdo);

return [
    'config' => $config,
    'pdo' => $pdo,
];
