<?php

declare(strict_types=1);

[$config, $pdo] = require dirname(__DIR__) . '/src/bootstrap.php';

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

function remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory) as $item) {
        if ($item->isDir() && !$item->isLink()) {
            remove_tree($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($directory);
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

function probe_duration(array $config, string $input): float
{
    $process = proc_open([
        $config['ffprobe'],
        '-v', 'error',
        '-show_entries', 'format=duration',
        '-of', 'default=noprint_wrappers=1:nokey=1',
        $input,
    ], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('Не удалось запустить FFprobe.');
    }

    $output = trim((string) stream_get_contents($pipes[1]));
    $error = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $duration = (float) $output;

    if ($exitCode !== 0 || $duration <= 0) {
        throw new RuntimeException('Не удалось определить длительность видео: ' . $error);
    }

    return $duration;
}

function convert_video(array $config, array $video, callable $onProgress): string
{
    $input = $config['paths']['uploads'] . '/' . $video['source_path'];
    if (!is_file($input)) {
        throw new RuntimeException('Исходный MP4-файл не найден.');
    }

    $relativeOutput = (string) $video['id'];
    $output = $config['paths']['hls'] . '/' . $relativeOutput;
    remove_tree($output);
    if (!mkdir($output, 0775, true) && !is_dir($output)) {
        throw new RuntimeException('Не удалось создать каталог HLS.');
    }

    $duration = probe_duration($config, $input);
    $onProgress(1, 'Анализ видео', $duration);

    $command = [
        $config['ffmpeg'],
        '-hide_banner',
        '-nostdin',
        '-y',
        '-i', $input,
        '-map', '0:v:0',
        '-map', '0:a:0?',
        '-vf', 'scale=-2:min(720\,ih)',
        '-c:v', 'libx264',
        '-preset', 'medium',
        '-crf', '23',
        '-profile:v', 'main',
        '-pix_fmt', 'yuv420p',
        '-c:a', 'aac',
        '-b:a', '128k',
        '-ac', '2',
        '-ar', '48000',
        '-force_key_frames', 'expr:gte(t,n_forced*6)',
        '-hls_time', '6',
        '-hls_playlist_type', 'vod',
        '-hls_segment_filename', $output . '/segment_%05d.ts',
        '-progress', 'pipe:1',
        '-nostats',
        $output . '/index.m3u8',
    ];

    $logFile = tempnam(sys_get_temp_dir(), 'kids-stream-ffmpeg-');
    if ($logFile === false) {
        throw new RuntimeException('Не удалось создать временный лог FFmpeg.');
    }

    $process = proc_open($command, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', $logFile, 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($logFile);
        remove_tree($output);
        throw new RuntimeException('Не удалось запустить FFmpeg.');
    }

    $lastProgress = 1;
    while (($line = fgets($pipes[1])) !== false) {
        [$key, $value] = array_pad(explode('=', trim($line), 2), 2, null);
        if ($key !== 'out_time_us' || !is_numeric($value)) {
            continue;
        }

        $progress = min(97, max(1, (int) floor(((float) $value / 1_000_000 / $duration) * 97)));
        if ($progress > $lastProgress) {
            $lastProgress = $progress;
            $onProgress($progress, 'Конвертация в HLS', $duration);
        }
    }
    fclose($pipes[1]);
    $exitCode = proc_close($process);
    $log = (string) @file_get_contents($logFile);
    @unlink($logFile);

    if ($exitCode !== 0 || !is_file($output . '/index.m3u8')) {
        remove_tree($output);
        $details = trim((string) $log);
        throw new RuntimeException('FFmpeg завершился с ошибкой: ' . mb_substr($details, -1800));
    }

    $onProgress(98, 'Создание обложки', $duration);
    $posterProcess = proc_open([
        $config['ffmpeg'],
        '-hide_banner',
        '-loglevel', 'error',
        '-nostdin',
        '-y',
        '-ss', (string) min(2, max(0, $duration / 3)),
        '-i', $input,
        '-frames:v', '1',
        '-vf', 'scale=640:-2',
        $output . '/poster.jpg',
    ], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ], $posterPipes);
    if (is_resource($posterProcess)) {
        proc_close($posterProcess);
    }

    $onProgress(99, 'Публикация', $duration);
    return $relativeOutput . '/index.m3u8';
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
    try {
        $progressStatement = $pdo->prepare(
            'UPDATE videos SET progress = :progress, processing_stage = :stage,
             duration_seconds = :duration, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $hlsPath = convert_video(
            $config,
            $video,
            static function (int $progress, string $stage, float $duration) use ($progressStatement, $video): void {
                $progressStatement->execute([
                    'progress' => $progress,
                    'stage' => $stage,
                    'duration' => $duration,
                    'id' => $video['id'],
                ]);
            },
        );
        $statement = $pdo->prepare(
            "UPDATE videos SET status = 'ready', hls_path = :hls_path,
             progress = 100, processing_stage = 'Опубликовано',
             error_message = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $statement->execute(['hls_path' => $hlsPath, 'id' => $video['id']]);
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
    }
} while (!$once);
