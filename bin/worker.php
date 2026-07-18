<?php

declare(strict_types=1);

['config' => $config, 'pdo' => $pdo] = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/AdaptiveHlsConverter.php';

$once = in_array('--once', $argv, true);
$daemon = in_array('--daemon', $argv, true);
$idleExitSeconds = 0;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--idle-exit=')) {
        $idleExitSeconds = max(0, (int) substr($argument, strlen('--idle-exit=')));
    }
}

$paths = worker_paths($config);
$lockHandle = fopen($paths['lock'], 'c+');
if ($lockHandle === false) {
    fwrite(STDERR, "Не удалось открыть файл блокировки воркера.\n");
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    // Уже работает другой экземпляр.
    exit(0);
}

ftruncate($lockHandle, 0);
fwrite($lockHandle, (string) getmypid());
fflush($lockHandle);
@file_put_contents($paths['pid'], (string) getmypid());

register_shutdown_function(static function () use ($lockHandle, $paths): void {
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
    if (is_file($paths['pid']) && (int) trim((string) @file_get_contents($paths['pid'])) === getmypid()) {
        @unlink($paths['pid']);
    }
});

if ($daemon) {
    ignore_user_abort(true);
    if (function_exists('set_time_limit')) {
        set_time_limit(0);
    }
}

function claim_video(PDO $pdo): ?array
{
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $video = $pdo->query(
            "SELECT * FROM videos WHERE status = 'queued' ORDER BY created_at ASC LIMIT 1"
        )->fetch();
        if (!$video) {
            $pdo->commit();
            return null;
        }

        $statement = $pdo->prepare(
            "UPDATE videos SET status = 'processing', progress = 0,
             processing_stage = 'Подготовка', updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'queued'"
        );
        $statement->execute(['id' => $video['id']]);
        $pdo->commit();

        return $statement->rowCount() === 1 ? $video : null;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

echo "FFmpeg-воркер запущен. Остановка: Ctrl+C.\n";

$idleStartedAt = null;

do {
    $video = claim_video($pdo);
    if (!$video) {
        if ($once) {
            break;
        }

        if ($idleExitSeconds > 0) {
            $idleStartedAt ??= time();
            if ((time() - $idleStartedAt) >= $idleExitSeconds) {
                echo "Очередь пуста, воркер завершается.\n";
                break;
            }
        }

        sleep(3);
        continue;
    }

    $idleStartedAt = null;

    echo "[{$video['id']}] Конвертация «{$video['title']}»...\n";
    $sourceResource = [];
    $outputResource = [];
    $videoStorage = null;
    try {
        $currentSettings = load_settings($pdo);
        $videoStorage = StorageManager::fromVideo($config, $currentSettings, $video);
        $videoStorage->validateConfiguration();
        $sourceResource = $videoStorage->materializeSource($video['source_path']);
        $outputResource = $videoStorage->prepareOutputRoot();

        $progressStatement = $pdo->prepare(
            'UPDATE videos SET progress = :progress, processing_stage = :stage,
             duration_seconds = :duration, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $converter = new AdaptiveHlsConverter($config, $currentSettings);
        $result = $converter->convert(
            $video,
            static function (int $progress, string $stage, float $duration) use ($progressStatement, $video): void {
                $progressStatement->execute([
                    'progress' => $progress,
                    'stage' => $stage,
                    'duration' => $duration,
                    'id' => $video['id'],
                ]);
            },
            $sourceResource['path'],
            $outputResource['path'],
        );
        $videoStorage->publishOutput((int) $video['id'], $outputResource['path']);
        $statement = $pdo->prepare(
            "UPDATE videos SET status = 'ready',
             hls_path = :hls_path,
             preview_vtt_path = :preview_vtt_path,
             qualities_json = :qualities_json,
             source_width = :source_width,
             source_height = :source_height,
             duration_seconds = :duration,
             progress = 100, processing_stage = 'Опубликовано',
             error_message = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $statement->execute([
            'hls_path' => $result['hls_path'],
            'preview_vtt_path' => $result['preview_vtt_path'],
            'qualities_json' => $result['qualities_json'],
            'source_width' => $result['source_width'],
            'source_height' => $result['source_height'],
            'duration' => $result['duration'],
            'id' => $video['id'],
        ]);
        echo "[{$video['id']}] Готово.\n";
    } catch (Throwable $exception) {
        $statement = $pdo->prepare(
            "UPDATE videos SET status = 'failed', processing_stage = 'Ошибка',
             error_message = :error,
             updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $statement->execute([
            'error' => mb_substr($exception->getMessage(), 0, 2000),
            'id' => $video['id'],
        ]);
        fwrite(STDERR, "[{$video['id']}] {$exception->getMessage()}\n");
    } finally {
        if ($videoStorage instanceof StorageManager) {
            $videoStorage->cleanupTemporary($sourceResource, $outputResource);
        }
    }
} while (!$once);
