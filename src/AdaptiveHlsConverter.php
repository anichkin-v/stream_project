<?php

declare(strict_types=1);

final class AdaptiveHlsConverter
{
    private const PROFILES = [
        480 => ['video_bitrate' => 1400000, 'maxrate' => '1498k', 'bufsize' => '2100k', 'audio' => '96k'],
        720 => ['video_bitrate' => 2800000, 'maxrate' => '2996k', 'bufsize' => '4200k', 'audio' => '128k'],
        1080 => ['video_bitrate' => 5000000, 'maxrate' => '5350k', 'bufsize' => '7500k', 'audio' => '160k'],
    ];

    public function __construct(
        private readonly array $config,
        private readonly array $settings,
    ) {
    }

    public function convert(
        array $video,
        callable $onProgress,
        ?string $inputPath = null,
        ?string $outputRoot = null,
    ): array
    {
        $input = $inputPath ?? $this->config['paths']['uploads'] . '/' . $video['source_path'];
        if (!is_file($input)) {
            throw new RuntimeException('Исходный MP4-файл не найден.');
        }

        $relativeOutput = (string) $video['id'];
        $output = ($outputRoot ?? $this->config['paths']['hls']) . '/' . $relativeOutput;
        $this->removeTree($output);
        if (!mkdir($output, 0775, true) && !is_dir($output)) {
            throw new RuntimeException('Не удалось создать каталог HLS.');
        }

        try {
            $media = $this->probe($input);
            $renditions = $this->chooseRenditions($media['height']);
            $onProgress(1, 'Анализ видео', $media['duration']);

            $qualityCount = count($renditions);
            foreach ($renditions as $index => &$rendition) {
                $start = 2 + (int) floor(($index / $qualityCount) * 82);
                $end = 2 + (int) floor((($index + 1) / $qualityCount) * 82);
                $rendition['width'] = $this->evenWidth(
                    $media['width'],
                    $media['height'],
                    $rendition['height'],
                );
                $this->convertRendition(
                    $input,
                    $output,
                    $media,
                    $rendition,
                    static function (float $ratio) use ($onProgress, $start, $end, $rendition, $media): void {
                        $progress = $start + (int) floor(($end - $start) * $ratio);
                        $onProgress($progress, 'Конвертация ' . $rendition['label'], $media['duration']);
                    },
                );
            }
            unset($rendition);

            $this->writeMasterPlaylist($output, $renditions, $media['has_audio']);

            $previewInterval = max(5, min(60, (int) ($this->settings['player_preview_interval'] ?? 10)));
            $onProgress(86, 'Превью таймлайна', $media['duration']);
            $previewRelative = $this->generateTimelinePreviews(
                $input,
                $output,
                $relativeOutput,
                $media['duration'],
                $previewInterval,
                static function (float $ratio) use ($onProgress, $media): void {
                    $onProgress(86 + (int) floor($ratio * 9), 'Превью таймлайна', $media['duration']);
                },
            );

            $onProgress(96, 'Создание обложки', $media['duration']);
            $this->generatePoster($input, $output, $media['duration']);
            $onProgress(99, 'Публикация', $media['duration']);

            return [
                'hls_path' => $relativeOutput . '/master.m3u8',
                'preview_vtt_path' => $previewRelative,
                'qualities_json' => json_encode(
                    array_map(
                        static fn (array $item): array => [
                            'label' => $item['label'],
                            'height' => $item['height'],
                            'width' => $item['width'],
                        ],
                        $renditions,
                    ),
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
                'source_width' => $media['width'],
                'source_height' => $media['height'],
                'duration' => $media['duration'],
            ];
        } catch (Throwable $exception) {
            $this->removeTree($output);
            throw $exception;
        }
    }

    private function probe(string $input): array
    {
        $process = proc_open([
            $this->config['ffprobe'],
            '-v', 'error',
            '-show_entries', 'format=duration:stream=codec_type,width,height',
            '-of', 'json',
            $input,
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Не удалось запустить FFprobe.');
        }

        $json = (string) stream_get_contents($pipes[1]);
        $error = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $data = json_decode($json, true);

        $videoStream = null;
        $hasAudio = false;
        foreach (($data['streams'] ?? []) as $stream) {
            if ($stream['codec_type'] === 'video' && $videoStream === null) {
                $videoStream = $stream;
            }
            if ($stream['codec_type'] === 'audio') {
                $hasAudio = true;
            }
        }

        $duration = (float) ($data['format']['duration'] ?? 0);
        $width = (int) ($videoStream['width'] ?? 0);
        $height = (int) ($videoStream['height'] ?? 0);
        if ($exitCode !== 0 || $duration <= 0 || $width <= 0 || $height <= 0) {
            throw new RuntimeException('Не удалось определить параметры видео: ' . $error);
        }

        return [
            'duration' => $duration,
            'width' => $width,
            'height' => $height,
            'has_audio' => $hasAudio,
        ];
    }

    private function chooseRenditions(int $sourceHeight): array
    {
        $renditions = [];
        foreach (self::PROFILES as $height => $profile) {
            if ($sourceHeight >= $height) {
                $renditions[] = [
                    ...$profile,
                    'height' => $height,
                    'label' => $height . 'p',
                ];
            }
        }

        if ($renditions === []) {
            $height = max(2, $sourceHeight - ($sourceHeight % 2));
            $renditions[] = [
                ...self::PROFILES[480],
                'height' => $height,
                'label' => $height . 'p',
            ];
        }

        return $renditions;
    }

    private function convertRendition(
        string $input,
        string $output,
        array $media,
        array $rendition,
        callable $onProgress,
    ): void {
        $directory = $output . '/' . $rendition['label'];
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог качества ' . $rendition['label']);
        }

        $command = [
            $this->config['ffmpeg'],
            '-hide_banner',
            '-nostdin',
            '-y',
            '-i', $input,
            '-map', '0:v:0',
            '-map', '0:a:0?',
            '-vf', 'scale=-2:' . $rendition['height'],
            '-c:v', 'libx264',
            '-preset', 'medium',
            '-crf', '22',
            '-profile:v', 'main',
            '-pix_fmt', 'yuv420p',
            '-maxrate', $rendition['maxrate'],
            '-bufsize', $rendition['bufsize'],
            '-sc_threshold', '0',
            '-force_key_frames', 'expr:gte(t,n_forced*6)',
        ];

        if ($media['has_audio']) {
            array_push(
                $command,
                '-c:a', 'aac',
                '-b:a', $rendition['audio'],
                '-ac', '2',
                '-ar', '48000',
            );
        }

        array_push(
            $command,
            '-hls_time', '6',
            '-hls_playlist_type', 'vod',
            '-hls_segment_filename', $directory . '/segment_%05d.ts',
            '-progress', 'pipe:1',
            '-nostats',
            $directory . '/index.m3u8',
        );

        $this->runFfmpeg(
            $command,
            $media['duration'],
            $onProgress,
            $directory . '/index.m3u8',
        );
    }

    private function writeMasterPlaylist(string $output, array $renditions, bool $hasAudio): void
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3', '#EXT-X-INDEPENDENT-SEGMENTS'];
        foreach ($renditions as $rendition) {
            $audioBitrate = $hasAudio ? (int) $rendition['audio'] * 1000 : 0;
            $bandwidth = $rendition['video_bitrate'] + $audioBitrate;
            $codecs = $hasAudio ? 'avc1.4d401f,mp4a.40.2' : 'avc1.4d401f';
            $lines[] = sprintf(
                '#EXT-X-STREAM-INF:BANDWIDTH=%d,AVERAGE-BANDWIDTH=%d,RESOLUTION=%dx%d,CODECS="%s",NAME="%s"',
                (int) ceil($bandwidth * 1.08),
                $bandwidth,
                $rendition['width'],
                $rendition['height'],
                $codecs,
                $rendition['label'],
            );
            $lines[] = $rendition['label'] . '/index.m3u8';
        }

        if (file_put_contents($output . '/master.m3u8', implode("\n", $lines) . "\n") === false) {
            throw new RuntimeException('Не удалось записать master HLS-плейлист.');
        }
    }

    private function generateTimelinePreviews(
        string $input,
        string $output,
        string $relativeOutput,
        float $duration,
        int $interval,
        callable $onProgress,
    ): ?string {
        $previewDirectory = $output . '/previews';
        if (!mkdir($previewDirectory, 0775, true) && !is_dir($previewDirectory)) {
            throw new RuntimeException('Не удалось создать каталог превью.');
        }

        $command = [
            $this->config['ffmpeg'],
            '-hide_banner',
            '-nostdin',
            '-y',
            '-i', $input,
            '-vf', 'select=isnan(prev_selected_t)+gte(t-prev_selected_t\,' . $interval . '),scale=240:-2,format=yuvj420p',
            '-fps_mode', 'vfr',
            '-c:v', 'mjpeg',
            '-q:v', '4',
            '-threads', '1',
            '-progress', 'pipe:1',
            '-nostats',
            $previewDirectory . '/thumb_%05d.jpg',
        ];
        $this->runFfmpeg($command, $duration, $onProgress);

        $files = glob($previewDirectory . '/thumb_*.jpg') ?: [];
        sort($files, SORT_NATURAL);
        if ($files === []) {
            return null;
        }

        $vtt = ["WEBVTT", ''];
        foreach ($files as $index => $file) {
            $start = $index * $interval;
            $end = min($duration, ($index + 1) * $interval);
            $vtt[] = $this->vttTime($start) . ' --> ' . $this->vttTime($end);
            $vtt[] = 'previews/' . basename($file);
            $vtt[] = '';
        }

        if (file_put_contents($output . '/thumbnails.vtt', implode("\n", $vtt)) === false) {
            throw new RuntimeException('Не удалось записать VTT превью.');
        }

        return $relativeOutput . '/thumbnails.vtt';
    }

    private function generatePoster(string $input, string $output, float $duration): void
    {
        $process = proc_open([
            $this->config['ffmpeg'],
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
        ], $pipes);

        if (is_resource($process)) {
            proc_close($process);
        }
    }

    private function runFfmpeg(
        array $command,
        float $duration,
        callable $onProgress,
        ?string $requiredOutput = null,
    ): void {
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
            throw new RuntimeException('Не удалось запустить FFmpeg.');
        }

        while (($line = fgets($pipes[1])) !== false) {
            [$key, $value] = array_pad(explode('=', trim($line), 2), 2, null);
            if ($key === 'out_time_us' && is_numeric($value)) {
                $onProgress(min(1, max(0, (float) $value / 1_000_000 / $duration)));
            }
        }
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        $log = trim((string) @file_get_contents($logFile));
        @unlink($logFile);

        if ($exitCode !== 0 || ($requiredOutput !== null && !is_file($requiredOutput))) {
            throw new RuntimeException('FFmpeg завершился с ошибкой: ' . mb_substr($log, -1800));
        }
    }

    private function evenWidth(int $sourceWidth, int $sourceHeight, int $targetHeight): int
    {
        $width = (int) round(($sourceWidth / $sourceHeight) * $targetHeight);

        return max(2, $width - ($width % 2));
    }

    private function vttTime(float $seconds): string
    {
        $milliseconds = (int) round($seconds * 1000);
        $hours = intdiv($milliseconds, 3_600_000);
        $minutes = intdiv($milliseconds % 3_600_000, 60_000);
        $remainingSeconds = intdiv($milliseconds % 60_000, 1000);
        $remainingMilliseconds = $milliseconds % 1000;

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $remainingSeconds, $remainingMilliseconds);
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new FilesystemIterator($directory) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->removeTree($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
    }
}
