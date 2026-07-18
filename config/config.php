<?php

declare(strict_types=1);

$root = dirname(__DIR__);

return [
    'app_name' => getenv('APP_NAME') ?: 'Детское видео',
    'base_url' => rtrim(getenv('APP_URL') ?: 'http://localhost', '/'),
    'session_name' => 'kids_stream_session',
    'auto_start_worker' => filter_var(
        getenv('AUTO_START_WORKER') === false ? '1' : getenv('AUTO_START_WORKER'),
        FILTER_VALIDATE_BOOL,
    ),
    'max_upload_bytes' => (int) (getenv('MAX_UPLOAD_BYTES') ?: 5 * 1024 * 1024 * 1024),
    'database' => [
        'dsn' => getenv('DB_DSN') ?: 'sqlite:' . $root . '/storage/database.sqlite',
        'user' => getenv('DB_USER') ?: null,
        'password' => getenv('DB_PASSWORD') ?: null,
    ],
    'paths' => [
        'root' => $root,
        'uploads' => $root . '/storage/uploads',
        'hls' => $root . '/public/media',
        'templates' => $root . '/templates',
    ],
    'ffmpeg' => getenv('FFMPEG_BIN') ?: 'ffmpeg',
    'ffprobe' => getenv('FFPROBE_BIN') ?: 'ffprobe',
];
