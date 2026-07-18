<?php

declare(strict_types=1);

final class StorageManager
{
    private mixed $s3Client = null;

    public function __construct(
        private readonly array $config,
        private readonly array $profile,
    ) {
    }

    public static function fromSettings(array $config, array $settings): self
    {
        return new self($config, self::profileFromSettings($config, $settings));
    }

    public static function fromVideo(array $config, array $settings, array $video): self
    {
        $saved = json_decode((string) ($video['storage_profile_json'] ?? ''), true);
        $profile = is_array($saved)
            ? self::normalizeProfile($config, $saved)
            : self::profileFromSettings($config, $settings);

        return new self($config, $profile);
    }

    public static function profileFromSettings(array $config, array $settings): array
    {
        return self::normalizeProfile($config, [
            'driver' => $settings['storage_driver'] ?? 'local',
            'source_path' => $settings['storage_source_path'] ?? $config['paths']['uploads'],
            'media_path' => $settings['storage_media_path'] ?? $config['paths']['hls'],
            'public_url' => $settings['storage_public_url'] ?? '/media',
            's3_bucket' => $settings['storage_s3_bucket'] ?? '',
            's3_region' => $settings['storage_s3_region'] ?? 'us-east-1',
            's3_prefix' => $settings['storage_s3_prefix'] ?? 'kidstub',
            's3_endpoint' => $settings['storage_s3_endpoint'] ?? '',
            's3_path_style' => ($settings['storage_s3_path_style'] ?? '0') === '1',
        ]);
    }

    public static function normalizeProfile(array $config, array $profile): array
    {
        $driver = in_array(($profile['driver'] ?? ''), ['local', 'network', 's3'], true)
            ? $profile['driver']
            : 'local';

        return [
            'driver' => $driver,
            'source_path' => rtrim((string) ($profile['source_path'] ?? $config['paths']['uploads']), '/'),
            'media_path' => rtrim((string) ($profile['media_path'] ?? $config['paths']['hls']), '/'),
            'public_url' => rtrim((string) ($profile['public_url'] ?? '/media'), '/'),
            's3_bucket' => trim((string) ($profile['s3_bucket'] ?? '')),
            's3_region' => trim((string) ($profile['s3_region'] ?? 'us-east-1')),
            's3_prefix' => trim((string) ($profile['s3_prefix'] ?? 'kidstub'), '/'),
            's3_endpoint' => rtrim(trim((string) ($profile['s3_endpoint'] ?? '')), '/'),
            's3_path_style' => filter_var($profile['s3_path_style'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    public function driver(): string
    {
        return $this->profile['driver'];
    }

    public function profileJson(): string
    {
        return json_encode($this->profile, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function storeUploadedFile(string $temporaryPath, string $filename): string
    {
        $filename = basename($filename);
        if ($this->driver() === 's3') {
            $this->s3()->putObject([
                'Bucket' => $this->profile['s3_bucket'],
                'Key' => $this->sourceObjectKey($filename),
                'SourceFile' => $temporaryPath,
                'ContentType' => 'video/mp4',
            ]);

            return $filename;
        }

        $this->ensureStorageDirectory($this->profile['source_path']);
        $destination = $this->profile['source_path'] . '/' . $filename;
        $moved = is_uploaded_file($temporaryPath)
            ? move_uploaded_file($temporaryPath, $destination)
            : rename($temporaryPath, $destination);
        if (!$moved) {
            throw new RuntimeException('Не удалось переместить MP4 в настроенное хранилище.');
        }

        return $filename;
    }

    /**
     * @return array{path: string, cleanup: bool}
     */
    public function materializeSource(string $sourceKey): array
    {
        if ($this->driver() !== 's3') {
            $path = $this->profile['source_path'] . '/' . basename($sourceKey);
            if (!is_file($path)) {
                throw new RuntimeException('Исходный файл отсутствует: ' . $path);
            }

            return ['path' => $path, 'cleanup' => false];
        }

        $temporary = tempnam($this->config['paths']['root'] . '/storage', 's3-source-');
        if ($temporary === false) {
            throw new RuntimeException('Не удалось создать временный файл для S3.');
        }
        try {
            $this->s3()->getObject([
                'Bucket' => $this->profile['s3_bucket'],
                'Key' => $this->sourceObjectKey(basename($sourceKey)),
                'SaveAs' => $temporary,
            ]);
        } catch (Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }

        return ['path' => $temporary, 'cleanup' => true];
    }

    /**
     * Корневой каталог, внутри которого конвертер создаст папку с ID видео.
     *
     * @return array{path: string, cleanup: bool}
     */
    public function prepareOutputRoot(): array
    {
        if ($this->driver() !== 's3') {
            $this->ensureStorageDirectory($this->profile['media_path']);

            return ['path' => $this->profile['media_path'], 'cleanup' => false];
        }

        $root = $this->config['paths']['root'] . '/storage/s3-output-' . bin2hex(random_bytes(8));
        $this->ensureDirectory($root);

        return ['path' => $root, 'cleanup' => true];
    }

    public function publishOutput(int $videoId, string $outputRoot): void
    {
        if ($this->driver() !== 's3') {
            return;
        }

        $directory = $outputRoot . '/' . $videoId;
        if (!is_dir($directory)) {
            throw new RuntimeException('Каталог результата конвертации не найден.');
        }

        $this->deleteS3Prefix($this->mediaObjectKey((string) $videoId) . '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr(
                $file->getPathname(),
                strlen($directory) + 1,
            ));
            $this->s3()->putObject([
                'Bucket' => $this->profile['s3_bucket'],
                'Key' => $this->mediaObjectKey($videoId . '/' . $relative),
                'SourceFile' => $file->getPathname(),
                'ContentType' => $this->contentType($file->getExtension()),
                'CacheControl' => str_ends_with($relative, '.m3u8')
                    ? 'public, max-age=60'
                    : 'public, max-age=604800, immutable',
            ]);
        }
    }

    public function publicUrl(string $relativePath): string
    {
        $base = $this->profile['public_url'];
        if ($base === '' && $this->driver() === 's3') {
            $bucket = rawurlencode($this->profile['s3_bucket']);
            $region = $this->profile['s3_region'];
            $prefix = $this->profile['s3_prefix'];
            $host = $region === 'us-east-1'
                ? "https://{$bucket}.s3.amazonaws.com"
                : "https://{$bucket}.s3.{$region}.amazonaws.com";
            $base = $host . ($prefix !== '' ? '/' . $prefix . '/media' : '/media');
        }
        if ($base === '') {
            $base = '/media';
        }

        return rtrim($base, '/') . '/' . ltrim($relativePath, '/');
    }

    public function deleteSource(string $sourceKey): bool
    {
        if ($this->driver() === 's3') {
            try {
                $this->s3()->deleteObject([
                    'Bucket' => $this->profile['s3_bucket'],
                    'Key' => $this->sourceObjectKey(basename($sourceKey)),
                ]);
                return true;
            } catch (Throwable) {
                return false;
            }
        }

        $path = $this->profile['source_path'] . '/' . basename($sourceKey);

        return !is_file($path) || @unlink($path);
    }

    public function deleteMedia(int $videoId): bool
    {
        if ($this->driver() === 's3') {
            try {
                $this->deleteS3Prefix($this->mediaObjectKey($videoId . '/'));
                return true;
            } catch (Throwable) {
                return false;
            }
        }

        return delete_tree($this->profile['media_path'] . '/' . $videoId);
    }

    public function cleanupTemporary(array ...$resources): void
    {
        foreach ($resources as $resource) {
            if (!($resource['cleanup'] ?? false)) {
                continue;
            }
            $path = (string) ($resource['path'] ?? '');
            if (is_dir($path)) {
                delete_tree($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function validateConfiguration(): void
    {
        if ($this->driver() === 's3') {
            if ($this->profile['s3_bucket'] === '' || $this->profile['s3_region'] === '') {
                throw new InvalidArgumentException('Для S3 укажите bucket и регион.');
            }
            $this->ensureAwsSdk();
            return;
        }

        foreach (['source_path', 'media_path'] as $key) {
            $path = $this->profile[$key];
            if (!$this->isAbsolutePath($path) || $path === '/') {
                throw new InvalidArgumentException('Пути хранилища должны быть абсолютными и не равны /.');
            }
            $this->ensureStorageDirectory($path);
            if (!is_writable($path)) {
                throw new RuntimeException('Нет прав записи в каталог: ' . $path);
            }
        }
    }

    private function s3(): mixed
    {
        if ($this->s3Client !== null) {
            return $this->s3Client;
        }
        $this->ensureAwsSdk();
        if ($this->profile['s3_bucket'] === '') {
            throw new RuntimeException('S3 bucket не настроен.');
        }

        $options = [
            'version' => 'latest',
            'region' => $this->profile['s3_region'],
            'use_path_style_endpoint' => $this->profile['s3_path_style'],
        ];
        if ($this->profile['s3_endpoint'] !== '') {
            $options['endpoint'] = $this->profile['s3_endpoint'];
        }
        $this->s3Client = new \Aws\S3\S3Client($options);

        return $this->s3Client;
    }

    private function ensureAwsSdk(): void
    {
        if (class_exists(\Aws\S3\S3Client::class)) {
            return;
        }

        $autoload = $this->config['paths']['root'] . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException(
                'Для S3 нужен AWS SDK. Выполните: composer install --no-dev',
            );
        }

        try {
            require_once $autoload;
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Каталог vendor повреждён или неполный. Удалите vendor/ и выполните '
                . 'composer install --no-dev. ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        if (!class_exists(\Aws\S3\S3Client::class)) {
            throw new RuntimeException(
                'AWS SDK не найден после загрузки Composer. Выполните: composer install --no-dev',
            );
        }
    }

    private function sourceObjectKey(string $filename): string
    {
        return $this->prefixedKey('source/' . ltrim($filename, '/'));
    }

    private function mediaObjectKey(string $path): string
    {
        return $this->prefixedKey('media/' . ltrim($path, '/'));
    }

    private function prefixedKey(string $path): string
    {
        return ($this->profile['s3_prefix'] !== '' ? $this->profile['s3_prefix'] . '/' : '') . $path;
    }

    private function deleteS3Prefix(string $prefix): void
    {
        $objects = [];
        $results = $this->s3()->getPaginator('ListObjectsV2', [
            'Bucket' => $this->profile['s3_bucket'],
            'Prefix' => $prefix,
        ]);
        foreach ($results as $result) {
            foreach (($result['Contents'] ?? []) as $object) {
                $objects[] = ['Key' => $object['Key']];
                if (count($objects) === 1000) {
                    $this->deleteS3Objects($objects);
                    $objects = [];
                }
            }
        }
        if ($objects !== []) {
            $this->deleteS3Objects($objects);
        }
    }

    private function deleteS3Objects(array $objects): void
    {
        $this->s3()->deleteObjects([
            'Bucket' => $this->profile['s3_bucket'],
            'Delete' => ['Objects' => $objects, 'Quiet' => true],
        ]);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог: ' . $directory);
        }
    }

    private function ensureStorageDirectory(string $directory): void
    {
        if ($this->driver() === 'network' && !is_dir($directory)) {
            throw new RuntimeException(
                'Сетевой каталог недоступен. Проверьте NFS/SMB mount: ' . $directory,
            );
        }
        $this->ensureDirectory($directory);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    private function contentType(string $extension): string
    {
        return match (strtolower($extension)) {
            'm3u8' => 'application/vnd.apple.mpegurl',
            'ts' => 'video/mp2t',
            'vtt' => 'text/vtt; charset=UTF-8',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
