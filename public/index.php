<?php

declare(strict_types=1);

['config' => $config, 'pdo' => $pdo] = require dirname(__DIR__) . '/src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = route_path();
$settings = load_settings($pdo);
$config['app_name'] = $settings['site_title'] ?? $config['app_name'];

if ($method === 'GET' && $path === '/') {
    $readyVideos = $pdo->query(
        "SELECT videos.id, videos.title, videos.description, videos.hls_path,
                videos.duration_seconds, videos.season_number, videos.episode_number,
                videos.created_at, videos.storage_profile_json, videos.series_id,
                series.title AS series_title, series.description AS series_description
         FROM videos
         LEFT JOIN series ON series.id = videos.series_id
         WHERE videos.status = 'ready'
         ORDER BY videos.created_at DESC, videos.id DESC"
    )->fetchAll();

    $catalog = [];
    foreach ($readyVideos as $readyVideo) {
        if ($readyVideo['series_id'] === null) {
            $readyVideo['episode_count'] = 1;
            $readyVideo['season_count'] = 0;
            $readyVideo['catalog_title'] = $readyVideo['title'];
            $readyVideo['catalog_description'] = $readyVideo['description'];
            $catalog['video:' . $readyVideo['id']] = $readyVideo;
            continue;
        }

        $key = 'series:' . $readyVideo['series_id'];
        if (!isset($catalog[$key])) {
            $readyVideo['episode_count'] = 0;
            $readyVideo['season_numbers'] = [];
            $readyVideo['latest_created_at'] = $readyVideo['created_at'];
            $readyVideo['catalog_title'] = $readyVideo['series_title'];
            $readyVideo['catalog_description'] = $readyVideo['series_description'] !== ''
                ? $readyVideo['series_description']
                : $readyVideo['description'];
            $catalog[$key] = $readyVideo;
        }

        $catalog[$key]['episode_count']++;
        $catalog[$key]['season_numbers'][(int) $readyVideo['season_number']] = true;

        $currentOrder = [
            (int) $catalog[$key]['season_number'],
            (int) $catalog[$key]['episode_number'],
            (int) $catalog[$key]['id'],
        ];
        $candidateOrder = [
            (int) $readyVideo['season_number'],
            (int) $readyVideo['episode_number'],
            (int) $readyVideo['id'],
        ];
        if ($candidateOrder < $currentOrder) {
            foreach ([
                'id', 'title', 'description', 'hls_path', 'duration_seconds',
                'season_number', 'episode_number', 'storage_profile_json',
            ] as $field) {
                $catalog[$key][$field] = $readyVideo[$field];
            }
        }
    }

    $videos = array_values($catalog);
    foreach ($videos as &$catalogVideo) {
        $catalogVideo['season_count'] = isset($catalogVideo['season_numbers'])
            ? count($catalogVideo['season_numbers'])
            : 0;
        unset($catalogVideo['season_numbers']);
        $catalogVideo['title'] = $catalogVideo['catalog_title'];
        $catalogVideo['description'] = $catalogVideo['catalog_description'];

        $catalogStorage = StorageManager::fromVideo($config, $settings, $catalogVideo);
        $catalogVideo['poster_url'] = $catalogStorage->publicUrl(
            (int) $catalogVideo['id'] . '/poster.jpg',
        );
    }
    unset($catalogVideo);

    usort($videos, static function (array $left, array $right): int {
        $leftDate = $left['latest_created_at'] ?? $left['created_at'];
        $rightDate = $right['latest_created_at'] ?? $right['created_at'];

        return strcmp((string) $rightDate, (string) $leftDate);
    });

    render('home', [
        'title' => $config['app_name'],
        'videos' => $videos,
        'settings' => $settings,
    ]);
}

if ($method === 'GET' && preg_match('#^/watch/(\d+)$#', $path, $matches)) {
    $statement = $pdo->prepare(
        "SELECT videos.*, series.title AS series_title
         FROM videos
         LEFT JOIN series ON series.id = videos.series_id
         WHERE videos.id = :id AND videos.status = 'ready'"
    );
    $statement->execute(['id' => (int) $matches[1]]);
    $video = $statement->fetch();
    if (!$video) {
        http_response_code(404);
        render('error', ['title' => 'Видео не найдено', 'message' => 'Видео не найдено или ещё не готово.']);
    }
    $videoStorage = StorageManager::fromVideo($config, $settings, $video);
    $video['hls_url'] = $videoStorage->publicUrl((string) $video['hls_path']);
    $video['poster_url'] = $videoStorage->publicUrl((int) $video['id'] . '/poster.jpg');
    $video['preview_vtt_url'] = $video['preview_vtt_path']
        ? $videoStorage->publicUrl((string) $video['preview_vtt_path'])
        : null;
    $video['media_base_url'] = $videoStorage->publicUrl((string) $video['id']);
    $episodes = [];
    $seasons = [];
    $previousEpisode = null;
    $nextEpisode = null;
    if ($video['series_id'] !== null) {
        $episodeStatement = $pdo->prepare(
            "SELECT id, title, season_number, episode_number
             FROM videos
             WHERE series_id = :series_id AND status = 'ready'
             ORDER BY season_number, episode_number, id"
        );
        $episodeStatement->execute(['series_id' => $video['series_id']]);
        $episodes = $episodeStatement->fetchAll();
        foreach ($episodes as $episode) {
            $seasons[(int) $episode['season_number']][] = $episode;
        }
        foreach ($episodes as $index => $episode) {
            if ((int) $episode['id'] === (int) $video['id']) {
                $previousEpisode = $episodes[$index - 1] ?? null;
                $nextEpisode = $episodes[$index + 1] ?? null;
                break;
            }
        }
    }

    $showQuality = ($settings['player_show_quality'] ?? '1') === '1';
    $showVolume = ($settings['player_show_volume'] ?? '1') === '1';
    $showFullscreen = ($settings['player_show_fullscreen'] ?? '1') === '1';
    $showNext = ($settings['player_show_next'] ?? '1') === '1';
    $showPreview = ($settings['player_show_preview'] ?? '1') === '1';
    $brandName = $settings['player_brand_name'] ?? 'KidsTub';

    $qualities = json_decode((string) ($video['qualities_json'] ?? '[]'), true) ?: [];
    foreach ($qualities as &$quality) {
        $quality['source'] = rtrim($video['media_base_url'], '/')
            . '/' . $quality['label'] . '/index.m3u8';
    }
    unset($quality);

    $playerConfig = [
        'source' => $video['hls_url'],
        'previewUrl' => $showPreview ? $video['preview_vtt_url'] : null,
        'qualities' => $qualities,
        'accentColor' => $settings['player_accent_color'] ?? '#ff4e63',
        'defaultVolume' => (int) ($settings['player_default_volume'] ?? 80),
        'seekStep' => (int) ($settings['player_seek_step'] ?? 10),
        'autoplayNext' => ($settings['player_autoplay_next'] ?? '1') === '1',
        'nextDelay' => (int) ($settings['player_next_delay'] ?? 5),
        'previousUrl' => $showNext && $previousEpisode ? '/watch/' . (int) $previousEpisode['id'] : null,
        'nextUrl' => $showNext && $nextEpisode ? '/watch/' . (int) $nextEpisode['id'] : null,
        'showPreview' => $showPreview,
        'brandName' => $brandName,
        'poster' => $video['poster_url'],
    ];

    $seasonsPayload = [];
    foreach ($seasons as $seasonNumber => $seasonEpisodes) {
        $seasonsPayload[(string) $seasonNumber] = array_map(
            static function (array $episode): array {
                return [
                    'id' => (int) $episode['id'],
                    'url' => '/watch/' . (int) $episode['id'],
                    'episode_number' => (int) $episode['episode_number'],
                    'title' => (string) $episode['title'],
                    'label' => episode_option_label($episode),
                ];
            },
            $seasonEpisodes,
        );
    }

    if (is_pjax_request()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'url' => '/watch/' . (int) $video['id'],
            'documentTitle' => ($video['title'] ?? $config['app_name']) . ' — ' . $config['app_name'],
            'heading' => $video['title'],
            'seriesTitle' => $video['series_title'] ?: $video['title'],
            'meta' => $video['series_title']
                ? sprintf(
                    '%s · Сезон %d · Серия %d',
                    $video['series_title'],
                    (int) $video['season_number'],
                    (int) $video['episode_number'],
                )
                : '',
            'description' => (string) $video['description'],
            'seasonNumber' => (int) $video['season_number'],
            'episodeId' => (int) $video['id'],
            'seasons' => $seasonsPayload,
            'showNextControls' => $showNext,
            'previousUrl' => $playerConfig['previousUrl'],
            'nextUrl' => $playerConfig['nextUrl'],
            'nextTitle' => $nextEpisode['title'] ?? null,
            'player' => $playerConfig,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    render('watch', [
        'title' => $video['title'],
        'video' => $video,
        'episodes' => $episodes,
        'seasons' => $seasons,
        'previousEpisode' => $previousEpisode,
        'nextEpisode' => $nextEpisode,
        'settings' => $settings,
        'playerConfig' => $playerConfig,
        'showQuality' => $showQuality,
        'showVolume' => $showVolume,
        'showFullscreen' => $showFullscreen,
        'showNext' => $showNext,
        'showPreview' => $showPreview,
        'brandName' => $brandName,
    ]);
}

if ($method === 'GET' && $path === '/admin/login') {
    if (is_admin()) {
        redirect('/admin');
    }
    render('login', ['title' => 'Вход администратора']);
}

if ($method === 'POST' && $path === '/admin/login') {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $statement = $pdo->prepare('SELECT id, password_hash FROM admins WHERE username = :username');
    $statement->execute(['username' => $username]);
    $admin = $statement->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        flash('error', 'Неверный логин или пароль.');
        redirect('/admin/login');
    }

    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    redirect('/admin');
}

if ($method === 'POST' && $path === '/admin/logout') {
    require_admin();
    verify_csrf();
    $_SESSION = [];
    session_destroy();
    redirect('/');
}

if ($method === 'GET' && $path === '/admin') {
    require_admin();
    $tab = (string) ($_GET['tab'] ?? 'content');
    if (!in_array($tab, ['content', 'player', 'storage', 'site'], true)) {
        $tab = 'content';
    }
    $videos = $pdo->query(
        'SELECT videos.*, series.title AS series_title,
                series.description AS series_description,
                series_seasons.title AS season_title,
                series_seasons.description AS season_description
         FROM videos
         LEFT JOIN series ON series.id = videos.series_id
         LEFT JOIN series_seasons
           ON series_seasons.series_id = videos.series_id
          AND series_seasons.season_number = videos.season_number
         ORDER BY videos.created_at DESC'
    )->fetchAll();
    $series = $pdo->query('SELECT id, title, description FROM series ORDER BY title')->fetchAll();
    $hasQueued = (bool) array_filter(
        $videos,
        static fn (array $video): bool => in_array($video['status'], ['queued', 'processing'], true),
    );
    if ($hasQueued) {
        ensure_worker_running($config);
    }
    render('admin', [
        'title' => 'Управление видео',
        'videos' => $videos,
        'maxUpload' => $config['max_upload_bytes'],
        'workerRunning' => is_worker_running($config),
        'tab' => $tab,
        'series' => $series,
        'settings' => $settings,
    ]);
}

if ($method === 'GET' && $path === '/admin/videos/status') {
    require_admin();
    $videos = $pdo->query(
        'SELECT id, status, progress, processing_stage, error_message, updated_at
         FROM videos ORDER BY created_at ASC'
    )->fetchAll();

    $queuePosition = 0;
    $needsWorker = false;
    foreach ($videos as &$video) {
        $video['id'] = (int) $video['id'];
        $video['progress'] = (int) $video['progress'];
        $video['queue_position'] = null;
        if ($video['status'] === 'queued') {
            $video['queue_position'] = ++$queuePosition;
            $needsWorker = true;
        }
        if ($video['status'] === 'processing') {
            $needsWorker = true;
        }
    }
    unset($video);

    if ($needsWorker) {
        ensure_worker_running($config);
    }

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'videos' => $videos,
        'worker_running' => is_worker_running($config),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if ($method === 'POST' && $path === '/admin/settings/player') {
    require_admin();
    verify_csrf();

    $accent = trim((string) ($_POST['player_accent_color'] ?? ''));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        flash('error', 'Цвет плеера должен быть в формате #RRGGBB.');
        redirect('/admin?tab=player');
    }

    save_settings($pdo, [
        'player_accent_color' => strtolower($accent),
        'player_default_volume' => max(0, min(100, (int) ($_POST['player_default_volume'] ?? 80))),
        'player_autoplay_next' => isset($_POST['player_autoplay_next']) ? '1' : '0',
        'player_next_delay' => max(0, min(30, (int) ($_POST['player_next_delay'] ?? 5))),
        'player_seek_step' => max(5, min(60, (int) ($_POST['player_seek_step'] ?? 10))),
        'player_preview_interval' => max(5, min(60, (int) ($_POST['player_preview_interval'] ?? 10))),
        'player_show_quality' => isset($_POST['player_show_quality']) ? '1' : '0',
        'player_show_volume' => isset($_POST['player_show_volume']) ? '1' : '0',
        'player_show_fullscreen' => isset($_POST['player_show_fullscreen']) ? '1' : '0',
        'player_show_next' => isset($_POST['player_show_next']) ? '1' : '0',
        'player_show_preview' => isset($_POST['player_show_preview']) ? '1' : '0',
        'player_brand_name' => mb_substr(
            trim((string) ($_POST['player_brand_name'] ?? 'KidsTub')) ?: 'KidsTub',
            0,
            30,
        ),
    ]);
    flash('success', 'Настройки детского плеера сохранены.');
    redirect('/admin?tab=player');
}

if ($method === 'POST' && $path === '/admin/settings/site') {
    require_admin();
    verify_csrf();

    $siteTitle = trim((string) ($_POST['site_title'] ?? ''));
    $siteTagline = trim((string) ($_POST['site_tagline'] ?? ''));
    if ($siteTitle === '' || mb_strlen($siteTitle) > 80 || mb_strlen($siteTagline) > 180) {
        flash('error', 'Проверьте название и описание сайта.');
        redirect('/admin?tab=site');
    }

    save_settings($pdo, [
        'site_title' => $siteTitle,
        'site_tagline' => $siteTagline,
    ]);
    flash('success', 'Настройки сайта сохранены.');
    redirect('/admin?tab=site');
}

if ($method === 'POST' && $path === '/admin/settings/storage') {
    require_admin();
    verify_csrf();

    $driver = (string) ($_POST['storage_driver'] ?? 'local');
    if (!in_array($driver, ['local', 'network', 's3'], true)) {
        flash('error', 'Неизвестный тип хранилища.');
        redirect('/admin?tab=storage');
    }

    $candidate = [
        ...$settings,
        'storage_driver' => $driver,
        'storage_source_path' => trim((string) ($_POST['storage_source_path'] ?? '')),
        'storage_media_path' => trim((string) ($_POST['storage_media_path'] ?? '')),
        'storage_public_url' => trim((string) ($_POST['storage_public_url'] ?? '')),
        'storage_s3_bucket' => trim((string) ($_POST['storage_s3_bucket'] ?? '')),
        'storage_s3_region' => trim((string) ($_POST['storage_s3_region'] ?? 'us-east-1')),
        'storage_s3_prefix' => trim((string) ($_POST['storage_s3_prefix'] ?? 'kidstub'), '/'),
        'storage_s3_endpoint' => trim((string) ($_POST['storage_s3_endpoint'] ?? '')),
        'storage_s3_path_style' => isset($_POST['storage_s3_path_style']) ? '1' : '0',
    ];

    try {
        StorageManager::fromSettings($config, $candidate)->validateConfiguration();
    } catch (Throwable $exception) {
        flash('error', 'Хранилище не сохранено: ' . $exception->getMessage());
        redirect('/admin?tab=storage');
    }

    save_settings($pdo, array_intersect_key($candidate, array_flip([
        'storage_driver',
        'storage_source_path',
        'storage_media_path',
        'storage_public_url',
        'storage_s3_bucket',
        'storage_s3_region',
        'storage_s3_prefix',
        'storage_s3_endpoint',
        'storage_s3_path_style',
    ])));
    flash('success', 'Настройки хранилища сохранены. Они применятся к новым загрузкам.');
    redirect('/admin?tab=storage');
}

if ($method === 'POST' && $path === '/admin/videos') {
    require_admin();
    verify_csrf();

    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $requestedSeriesId = max(0, (int) ($_POST['series_id'] ?? 0));
    $newSeriesTitle = trim((string) ($_POST['new_series_title'] ?? ''));
    $seasonNumber = max(1, (int) ($_POST['season_number'] ?? 1));
    $episodeNumber = (int) ($_POST['episode_number'] ?? 0);
    $file = $_FILES['video'] ?? null;

    if ($title === '' || mb_strlen($title) > 180) {
        flash('error', 'Укажите название длиной до 180 символов.');
        redirect('/admin');
    }
    if ($requestedSeriesId > 0 && $newSeriesTitle !== '') {
        flash('error', 'Выберите существующий сериал или создайте новый — не оба варианта.');
        redirect('/admin');
    }
    if (mb_strlen($newSeriesTitle) > 180) {
        flash('error', 'Название нового сериала должно быть не длиннее 180 символов.');
        redirect('/admin');
    }

    $seriesId = null;
    if ($requestedSeriesId > 0) {
        $seriesLookup = $pdo->prepare('SELECT id FROM series WHERE id = :id');
        $seriesLookup->execute(['id' => $requestedSeriesId]);
        $seriesId = $seriesLookup->fetchColumn();
        if ($seriesId === false) {
            flash('error', 'Выбранный сериал не найден.');
            redirect('/admin');
        }
        $seriesId = (int) $seriesId;
    }
    if ($newSeriesTitle !== '') {
        $existingSeries = $pdo->prepare('SELECT id FROM series WHERE title = :title');
        $existingSeries->execute(['title' => $newSeriesTitle]);
        if ($existingSeries->fetchColumn() !== false) {
            flash('error', 'Такой сериал уже существует. Выберите его в списке.');
            redirect('/admin');
        }
    }
    if (($seriesId !== null || $newSeriesTitle !== '') && $episodeNumber < 1) {
        flash('error', 'Для сериала укажите номер серии.');
        redirect('/admin');
    }
    if ($seriesId !== null) {
        $duplicateEpisode = $pdo->prepare(
            'SELECT id FROM videos
             WHERE series_id = :series_id
               AND season_number = :season_number
               AND episode_number = :episode_number
             LIMIT 1'
        );
        $duplicateEpisode->execute([
            'series_id' => $seriesId,
            'season_number' => $seasonNumber,
            'episode_number' => $episodeNumber,
        ]);
        if ($duplicateEpisode->fetchColumn() !== false) {
            flash('error', "Сезон {$seasonNumber}, серия {$episodeNumber} уже существуют.");
            redirect('/admin');
        }
    }
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('error', 'MP4 не загружен. Проверьте лимиты upload_max_filesize и post_max_size в PHP.');
        redirect('/admin');
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $config['max_upload_bytes']) {
        flash('error', 'Размер файла превышает разрешённый лимит.');
        redirect('/admin');
    }

    $originalName = basename((string) $file['name']);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    if ($extension !== 'mp4' || !in_array($mime, ['video/mp4', 'application/mp4'], true)) {
        flash('error', 'Разрешены только корректные MP4-файлы.');
        redirect('/admin');
    }

    $filename = bin2hex(random_bytes(16)) . '.mp4';
    $videoStorage = StorageManager::fromSettings($config, $settings);
    try {
        $videoStorage->validateConfiguration();
        $sourceKey = $videoStorage->storeUploadedFile((string) $file['tmp_name'], $filename);
    } catch (Throwable $exception) {
        flash('error', 'Не удалось сохранить MP4: ' . $exception->getMessage());
        redirect('/admin');
    }

    try {
        $pdo->beginTransaction();
        if ($newSeriesTitle !== '') {
            $seriesStatement = $pdo->prepare(
                'INSERT INTO series (title) VALUES (:title)
                 ON CONFLICT(title) DO UPDATE SET updated_at = CURRENT_TIMESTAMP'
            );
            $seriesStatement->execute(['title' => $newSeriesTitle]);
            $seriesLookup = $pdo->prepare('SELECT id FROM series WHERE title = :title');
            $seriesLookup->execute(['title' => $newSeriesTitle]);
            $seriesId = (int) $seriesLookup->fetchColumn();

            $duplicateEpisode = $pdo->prepare(
                'SELECT id FROM videos
                 WHERE series_id = :series_id
                   AND season_number = :season_number
                   AND episode_number = :episode_number
                 LIMIT 1'
            );
            $duplicateEpisode->execute([
                'series_id' => $seriesId,
                'season_number' => $seasonNumber,
                'episode_number' => $episodeNumber,
            ]);
            if ($duplicateEpisode->fetchColumn() !== false) {
                throw new RuntimeException(
                    "Сезон {$seasonNumber}, серия {$episodeNumber} уже существуют.",
                );
            }
        }

        if ($seriesId !== null) {
            $seasonStatement = $pdo->prepare(
                'INSERT OR IGNORE INTO series_seasons (series_id, season_number)
                 VALUES (:series_id, :season_number)'
            );
            $seasonStatement->execute([
                'series_id' => $seriesId,
                'season_number' => $seasonNumber,
            ]);
        }

        $statement = $pdo->prepare(
            'INSERT INTO videos (
                title, description, original_name, source_path,
                series_id, season_number, episode_number, storage_profile_json
             ) VALUES (
                :title, :description, :original_name, :source_path,
                :series_id, :season_number, :episode_number, :storage_profile_json
             )'
        );
        $statement->execute([
            'title' => $title,
            'description' => $description,
            'original_name' => $originalName,
            'source_path' => $sourceKey,
            'series_id' => $seriesId,
            'season_number' => $seasonNumber,
            'episode_number' => $seriesId !== null ? $episodeNumber : null,
            'storage_profile_json' => $videoStorage->profileJson(),
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $videoStorage->deleteSource($sourceKey);
        throw $exception;
    }

    flash('success', 'Видео загружено и поставлено в очередь.');
    if (!ensure_worker_running($config)) {
        flash('error', 'Не удалось автоматически запустить воркер. Проверьте права на storage/ и PATH для PHP/FFmpeg.');
    }
    redirect('/admin');
}

if ($method === 'POST' && preg_match('#^/admin/series/(\d+)$#', $path, $matches)) {
    require_admin();
    verify_csrf();
    $seriesId = (int) $matches[1];
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($title === '' || mb_strlen($title) > 180 || mb_strlen($description) > 5000) {
        flash('error', 'Проверьте название и описание сериала.');
        redirect('/admin');
    }

    $duplicate = $pdo->prepare('SELECT id FROM series WHERE title = :title AND id != :id');
    $duplicate->execute(['title' => $title, 'id' => $seriesId]);
    if ($duplicate->fetchColumn() !== false) {
        flash('error', 'Сериал с таким названием уже существует.');
        redirect('/admin');
    }

    $statement = $pdo->prepare(
        'UPDATE series
         SET title = :title, description = :description, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute(['title' => $title, 'description' => $description, 'id' => $seriesId]);
    flash('success', $statement->rowCount() ? 'Сериал обновлён.' : 'Сериал не найден.');
    redirect('/admin');
}

if ($method === 'POST'
    && preg_match('#^/admin/series/(\d+)/seasons/(\d+)$#', $path, $matches)
) {
    require_admin();
    verify_csrf();
    $seriesId = (int) $matches[1];
    $currentNumber = max(1, (int) $matches[2]);
    $seasonNumber = max(1, (int) ($_POST['season_number'] ?? $currentNumber));
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if (mb_strlen($title) > 180 || mb_strlen($description) > 5000) {
        flash('error', 'Название сезона должно быть до 180 символов, описание — до 5000.');
        redirect('/admin');
    }

    try {
        $pdo->beginTransaction();
        $seriesLookup = $pdo->prepare('SELECT id FROM series WHERE id = :id');
        $seriesLookup->execute(['id' => $seriesId]);
        if ($seriesLookup->fetchColumn() === false) {
            throw new RuntimeException('Сериал не найден.');
        }

        if ($seasonNumber !== $currentNumber) {
            $targetLookup = $pdo->prepare(
                'SELECT 1
                 FROM videos
                 WHERE series_id = :series_id AND season_number = :season_number
                 UNION ALL
                 SELECT 1
                 FROM series_seasons
                 WHERE series_id = :series_id AND season_number = :season_number
                 LIMIT 1'
            );
            $targetLookup->execute([
                'series_id' => $seriesId,
                'season_number' => $seasonNumber,
            ]);
            if ($targetLookup->fetchColumn() !== false) {
                throw new RuntimeException("Сезон {$seasonNumber} уже существует.");
            }

            $moveVideos = $pdo->prepare(
                'UPDATE videos
                 SET season_number = :new_number, updated_at = CURRENT_TIMESTAMP
                 WHERE series_id = :series_id AND season_number = :current_number'
            );
            $moveVideos->execute([
                'new_number' => $seasonNumber,
                'series_id' => $seriesId,
                'current_number' => $currentNumber,
            ]);
        }

        $deleteOld = $pdo->prepare(
            'DELETE FROM series_seasons
             WHERE series_id = :series_id AND season_number = :current_number'
        );
        $deleteOld->execute(['series_id' => $seriesId, 'current_number' => $currentNumber]);
        $saveSeason = $pdo->prepare(
            'INSERT INTO series_seasons (series_id, season_number, title, description)
             VALUES (:series_id, :season_number, :title, :description)
             ON CONFLICT(series_id, season_number) DO UPDATE SET
                title = excluded.title,
                description = excluded.description,
                updated_at = CURRENT_TIMESTAMP'
        );
        $saveSeason->execute([
            'series_id' => $seriesId,
            'season_number' => $seasonNumber,
            'title' => $title,
            'description' => $description,
        ]);
        $pdo->commit();
        flash('success', 'Сезон обновлён.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $exception->getMessage());
    }
    redirect('/admin');
}

if ($method === 'POST' && preg_match('#^/admin/videos/(\d+)$#', $path, $matches)) {
    require_admin();
    verify_csrf();
    $videoId = (int) $matches[1];
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($title === '' || mb_strlen($title) > 180 || mb_strlen($description) > 5000) {
        flash('error', 'Проверьте название и описание видео.');
        redirect('/admin');
    }

    $lookup = $pdo->prepare('SELECT id, series_id, season_number, episode_number FROM videos WHERE id = :id');
    $lookup->execute(['id' => $videoId]);
    $video = $lookup->fetch();
    if (!$video) {
        flash('error', 'Видео не найдено.');
        redirect('/admin');
    }

    $seasonNumber = (int) $video['season_number'];
    $episodeNumber = $video['episode_number'] !== null ? (int) $video['episode_number'] : null;
    if ($video['series_id'] !== null) {
        $seasonNumber = max(1, (int) ($_POST['season_number'] ?? $seasonNumber));
        $episodeNumber = max(1, (int) ($_POST['episode_number'] ?? $episodeNumber));
        $duplicate = $pdo->prepare(
            'SELECT id FROM videos
             WHERE series_id = :series_id
               AND season_number = :season_number
               AND episode_number = :episode_number
               AND id != :id
             LIMIT 1'
        );
        $duplicate->execute([
            'series_id' => (int) $video['series_id'],
            'season_number' => $seasonNumber,
            'episode_number' => $episodeNumber,
            'id' => $videoId,
        ]);
        if ($duplicate->fetchColumn() !== false) {
            flash('error', "Сезон {$seasonNumber}, серия {$episodeNumber} уже существуют.");
            redirect('/admin');
        }
    }

    try {
        $pdo->beginTransaction();
        if ($video['series_id'] !== null) {
            $seasonStatement = $pdo->prepare(
                'INSERT OR IGNORE INTO series_seasons (series_id, season_number)
                 VALUES (:series_id, :season_number)'
            );
            $seasonStatement->execute([
                'series_id' => (int) $video['series_id'],
                'season_number' => $seasonNumber,
            ]);
        }
        $statement = $pdo->prepare(
            'UPDATE videos
             SET title = :title,
                 description = :description,
                 season_number = :season_number,
                 episode_number = :episode_number,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'title' => $title,
            'description' => $description,
            'season_number' => $seasonNumber,
            'episode_number' => $episodeNumber,
            'id' => $videoId,
        ]);
        $pdo->commit();
        flash('success', $video['series_id'] !== null ? 'Серия обновлена.' : 'Видео обновлено.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Не удалось сохранить изменения: ' . $exception->getMessage());
    }
    redirect('/admin');
}

if ($method === 'POST' && preg_match('#^/admin/videos/(\d+)/retry$#', $path, $matches)) {
    require_admin();
    verify_csrf();
    $statement = $pdo->prepare(
        "UPDATE videos SET status = 'queued', progress = 0, processing_stage = NULL,
         error_message = NULL, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND status = 'failed'"
    );
    $statement->execute(['id' => (int) $matches[1]]);
    flash('success', 'Задание повторно поставлено в очередь.');
    ensure_worker_running($config);
    redirect('/admin');
}

if ($method === 'POST' && preg_match('#^/admin/videos/(\d+)/reprocess$#', $path, $matches)) {
    require_admin();
    verify_csrf();
    $statement = $pdo->prepare(
        "UPDATE videos SET status = 'queued', progress = 0, processing_stage = NULL,
         hls_path = NULL, preview_vtt_path = NULL, qualities_json = NULL,
         error_message = NULL, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND status IN ('ready', 'failed')"
    );
    $statement->execute(['id' => (int) $matches[1]]);
    flash('success', 'Видео поставлено в очередь для адаптивной конвертации.');
    ensure_worker_running($config);
    redirect('/admin');
}

if ($method === 'POST' && preg_match('#^/admin/videos/(\d+)/delete$#', $path, $matches)) {
    require_admin();
    verify_csrf();
    $videoId = (int) $matches[1];

    $statement = $pdo->prepare(
        'SELECT id, title, source_path, status, storage_profile_json
         FROM videos WHERE id = :id'
    );
    $statement->execute(['id' => $videoId]);
    $video = $statement->fetch();

    if (!$video) {
        flash('error', 'Видео уже удалено или не существует.');
        redirect('/admin');
    }
    if ($video['status'] === 'processing') {
        flash('error', 'Нельзя удалить видео во время конвертации. Дождитесь завершения обработки.');
        redirect('/admin');
    }

    $delete = $pdo->prepare("DELETE FROM videos WHERE id = :id AND status != 'processing'");
    $delete->execute(['id' => $videoId]);
    if ($delete->rowCount() !== 1) {
        flash('error', 'Видео уже перешло в обработку и не было удалено.');
        redirect('/admin');
    }

    $videoStorage = StorageManager::fromVideo($config, $settings, $video);
    $sourceDeleted = $videoStorage->deleteSource((string) $video['source_path']);
    $mediaDeleted = $videoStorage->deleteMedia($videoId);

    if (!$sourceDeleted || !$mediaDeleted) {
        flash('error', 'Запись удалена, но часть файлов не удалось очистить с диска.');
    } else {
        flash('success', 'Видео «' . $video['title'] . '» и его файлы удалены.');
    }
    redirect('/admin');
}

http_response_code(404);
render('error', ['title' => 'Страница не найдена', 'message' => 'Такой страницы не существует.']);
