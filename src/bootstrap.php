<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/config/config.php';

$storageRoot = $config['paths']['root'] . '/storage';
$errorLog = $storageRoot . '/php-error.log';

set_exception_handler(static function (Throwable $exception) use ($errorLog, $storageRoot): void {
    if (is_dir($storageRoot) || @mkdir($storageRoot, 0775, true) || is_dir($storageRoot)) {
        @file_put_contents(
            $errorLog,
            '[' . date('c') . '] ' . $exception . "\n\n",
            FILE_APPEND,
        );
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $file = htmlspecialchars($exception->getFile(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $line = (int) $exception->getLine();
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Ошибка KidsTub</title>';
    echo '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:720px;margin:48px auto;padding:0 16px;color:#1c2434}';
    echo 'code{display:block;margin-top:16px;padding:14px;border-radius:10px;background:#f4f6fb;white-space:pre-wrap}</style></head><body>';
    echo '<h1>Сервер вернул ошибку</h1>';
    echo '<p>Страница не открылась из‑за ошибки PHP. Подробности также пишутся в <code>storage/php-error.log</code>.</p>';
    echo '<code>' . $message . "\n" . $file . ':' . $line . '</code>';
    echo '</body></html>';
    exit(1);
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

$requiredDirectories = [
    $config['paths']['uploads'],
    $config['paths']['hls'],
    $storageRoot,
    $storageRoot . '/sessions',
];

foreach ($requiredDirectories as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Не удалось создать каталог: {$directory}");
    }
}

try {
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
} catch (Throwable $exception) {
    throw new RuntimeException(
        'Не удалось открыть базу данных. Проверьте права на каталог storage/ '
        . '(владелец должен быть www-data): ' . $exception->getMessage(),
        0,
        $exception,
    );
}

if (str_starts_with($config['database']['dsn'], 'sqlite:')) {
    try {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->query('SELECT count(*) FROM sqlite_master')->fetchColumn();
        $pdo->exec('PRAGMA journal_mode = WAL');
    } catch (Throwable $exception) {
        $detail = $exception->getMessage();
        if (str_contains($detail, 'malformed')) {
            throw new RuntimeException(
                'Файл SQLite повреждён (database disk image is malformed). '
                . 'Остановите воркер, переименуйте storage/database.sqlite* в *.broken, '
                . 'затем выполните: php bin/init-db.php и php bin/create-admin.php. '
                . $detail,
                0,
                $exception,
            );
        }
        throw new RuntimeException(
            'SQLite недоступен для записи. Выполните: '
            . 'chown -R www-data:www-data storage public/media && chmod -R 775 storage public/media. '
            . $detail,
            0,
            $exception,
        );
    }
}

if (PHP_SAPI !== 'cli') {
    session_name($config['session_name']);
    session_save_path($storageRoot . '/sessions');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => str_starts_with((string) ($config['base_url'] ?? ''), 'https://'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    if (!session_start()) {
        throw new RuntimeException(
            'Не удалось запустить PHP-сессию. Проверьте права на storage/sessions/.',
        );
    }
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/worker.php';

$storageManagerFile = __DIR__ . '/StorageManager.php';
if (!is_file($storageManagerFile)) {
    throw new RuntimeException(
        'Не найден файл src/StorageManager.php. Залейте актуальные файлы проекта на сервер.',
    );
}
require_once $storageManagerFile;

try {
    ensure_schema($pdo);
} catch (Throwable $exception) {
    throw new RuntimeException(
        'Миграция БД не выполнена. Проверьте права на storage/database.sqlite: '
        . $exception->getMessage(),
        0,
        $exception,
    );
}

return [
    'config' => $config,
    'pdo' => $pdo,
];
