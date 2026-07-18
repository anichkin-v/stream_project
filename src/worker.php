<?php

declare(strict_types=1);

function worker_paths(array $config): array
{
    $storage = $config['paths']['root'] . '/storage';

    return [
        'lock' => $storage . '/worker.lock',
        'pid' => $storage . '/worker.pid',
        'log' => $storage . '/worker.log',
        'script' => $config['paths']['root'] . '/bin/worker.php',
    ];
}

function is_worker_process_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }

    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $output = [];
        @exec('tasklist /FI "PID eq ' . $pid . '" 2>NUL', $output);

        return isset($output[1]) && str_contains(implode("\n", $output), (string) $pid);
    }

    return false;
}

function is_worker_running(array $config): bool
{
    $paths = worker_paths($config);
    $handle = @fopen($paths['lock'], 'c+');
    if ($handle === false) {
        return false;
    }

    $acquired = flock($handle, LOCK_EX | LOCK_NB);
    if ($acquired) {
        flock($handle, LOCK_UN);
        fclose($handle);

        return false;
    }

    fclose($handle);

    return true;
}

/**
 * Запускает фонового воркера, если он ещё не работает.
 */
function ensure_worker_running(array $config): bool
{
    if (is_worker_running($config)) {
        return true;
    }
    if (!($config['auto_start_worker'] ?? true)) {
        return false;
    }

    $paths = worker_paths($config);
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';

    if (PHP_OS_FAMILY === 'Windows') {
        $command = sprintf(
            'start /B "" %s %s --daemon --idle-exit=60 >> %s 2>&1',
            escapeshellarg($php),
            escapeshellarg($paths['script']),
            escapeshellarg($paths['log']),
        );
        pclose(popen($command, 'r'));
    } else {
        $command = sprintf(
            'nohup %s %s --daemon --idle-exit=60 >> %s 2>&1 & echo $!',
            escapeshellarg($php),
            escapeshellarg($paths['script']),
            escapeshellarg($paths['log']),
        );
        $output = [];
        @exec($command, $output);
        $pid = (int) trim((string) ($output[0] ?? ''));
        if ($pid > 0) {
            @file_put_contents($paths['pid'], (string) $pid);
        }
    }

    for ($attempt = 0; $attempt < 15; $attempt++) {
        usleep(100_000);
        if (is_worker_running($config)) {
            return true;
        }
    }

    if (is_file($paths['pid'])) {
        $pid = (int) trim((string) @file_get_contents($paths['pid']));
        if (is_worker_process_alive($pid)) {
            return true;
        }
    }

    return false;
}
