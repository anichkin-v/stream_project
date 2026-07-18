<?php

declare(strict_types=1);

$publicRoot = __DIR__ . '/public';
$requestPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$candidate = realpath($publicRoot . $requestPath);
$resolvedPublicRoot = realpath($publicRoot);

if (
    $requestPath !== '/'
    && $candidate !== false
    && $resolvedPublicRoot !== false
    && str_starts_with($candidate, $resolvedPublicRoot . DIRECTORY_SEPARATOR)
    && is_file($candidate)
) {
    $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    $contentType = match ($extension) {
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'm3u8' => 'application/vnd.apple.mpegurl',
        'ts' => 'video/mp2t',
        'vtt' => 'text/vtt; charset=UTF-8',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        default => mime_content_type($candidate) ?: 'application/octet-stream',
    };
    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=3600');
    readfile($candidate);
    return;
}

require $publicRoot . '/index.php';
