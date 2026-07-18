<?php
$statusLabels = [
    'queued' => 'В очереди',
    'processing' => 'Конвертируется',
    'ready' => 'Готово',
    'failed' => 'Ошибка',
];
$queuePositions = [];
$queuedIndex = 0;
$seriesFolders = [];
$standaloneVideos = [];
foreach ($videos as $contentVideo) {
    if ($contentVideo['status'] === 'queued') {
        $queuePositions[(int) $contentVideo['id']] = ++$queuedIndex;
    }
    if ($contentVideo['series_id'] === null) {
        $standaloneVideos[] = $contentVideo;
        continue;
    }

    $seriesId = (int) $contentVideo['series_id'];
    if (!isset($seriesFolders[$seriesId])) {
        $seriesFolders[$seriesId] = [
            'id' => $seriesId,
            'title' => $contentVideo['series_title'],
            'description' => $contentVideo['series_description'] ?? '',
            'seasons' => [],
            'total' => 0,
            'ready' => 0,
        ];
    }
    $seasonNumber = (int) $contentVideo['season_number'];
    if (!isset($seriesFolders[$seriesId]['seasons'][$seasonNumber])) {
        $seriesFolders[$seriesId]['seasons'][$seasonNumber] = [
            'title' => $contentVideo['season_title'] ?? '',
            'description' => $contentVideo['season_description'] ?? '',
            'episodes' => [],
        ];
    }
    $seriesFolders[$seriesId]['seasons'][$seasonNumber]['episodes'][] = $contentVideo;
    $seriesFolders[$seriesId]['total']++;
    if ($contentVideo['status'] === 'ready') {
        $seriesFolders[$seriesId]['ready']++;
    }
}
foreach ($seriesFolders as &$seriesFolder) {
    ksort($seriesFolder['seasons'], SORT_NUMERIC);
    foreach ($seriesFolder['seasons'] as &$season) {
        usort($season['episodes'], static function (array $left, array $right): int {
            return [(int) $left['episode_number'], (int) $left['id']]
                <=> [(int) $right['episode_number'], (int) $right['id']];
        });
    }
    unset($season);
}
unset($seriesFolder);
?>
<div class="admin-heading">
    <div>
        <p class="eyebrow">Администрирование</p>
        <h1>
            <?= match ($tab) {
                'player' => 'Настройки плеера',
                'storage' => 'Настройки хранилища',
                'site' => 'Настройки сайта',
                default => 'Управление контентом',
            } ?>
        </h1>
    </div>
    <a class="button button-secondary" href="/" target="_blank">Открыть сайт ↗</a>
</div>

<div class="admin-layout">
    <main class="admin-main">
        <?php if ($tab === 'content'): ?>
            <section class="panel">
                <div class="panel-title">
                    <div>
                        <h2>Добавить MP4</h2>
                        <p class="muted">Будут созданы 480p, 720p и 1080p — если позволяет исходник.</p>
                    </div>
                </div>
                <form method="post" action="/admin/videos" enctype="multipart/form-data" class="upload-form">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <label>
                        Название серии / видео
                        <input name="title" maxlength="180" required>
                    </label>
                    <label>
                        Описание
                        <textarea name="description" rows="4"></textarea>
                    </label>
                    <div class="series-assignment">
                        <label>
                            Добавить серию в существующий контент
                            <select name="series_id" id="upload-series">
                                <option value="">Отдельное видео (не сериал)</option>
                                <?php foreach ($series as $item): ?>
                                    <option value="<?= (int) $item['id'] ?>">
                                        <?= e($item['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Или создать новый сериал
                            <input name="new_series_title" id="new-series-title" maxlength="180"
                                   placeholder="Например, Приключения Миши">
                        </label>
                        <label>
                            Номер сезона
                            <input name="season_number" type="number" min="1" value="1">
                        </label>
                        <label>
                            Номер серии
                            <input name="episode_number" type="number" min="1" placeholder="1">
                        </label>
                    </div>
                    <p class="field-help">
                        Для продолжения сериала выберите существующий контент. В каталоге все его серии
                        будут показаны одной карточкой.
                    </p>
                    <label>
                        Видео MP4 (до <?= e(format_bytes($maxUpload)) ?>)
                        <input name="video" type="file" accept="video/mp4,.mp4" required>
                    </label>
                    <button class="button" type="submit">Загрузить и конвертировать</button>
                </form>
            </section>
            <script>
            (() => {
                const series = document.getElementById('upload-series');
                const newSeries = document.getElementById('new-series-title');
                if (!series || !newSeries) return;
                const sync = () => {
                    newSeries.disabled = series.value !== '';
                    if (newSeries.disabled) newSeries.value = '';
                    series.disabled = newSeries.value.trim() !== '';
                };
                series.addEventListener('change', sync);
                newSeries.addEventListener('input', sync);
                sync();
            })();
            </script>

            <section class="panel">
                <div class="panel-title">
                    <div>
                        <h2>Контент</h2>
                        <p class="muted">Статусы обновляются автоматически</p>
                    </div>
                    <span class="live-indicator <?= $workerRunning ? '' : 'is-offline' ?>">
                        <i></i> <?= $workerRunning ? 'Воркер работает' : 'Воркер не запущен' ?>
                    </span>
                </div>

                <?php if (!$videos): ?>
                    <p class="muted">Видео ещё не загружались.</p>
                <?php else: ?>
                    <?php if (array_filter($videos, static fn (array $video): bool => $video['status'] === 'queued')): ?>
                        <div class="worker-hint">
                            <strong>Есть задания в очереди.</strong>
                            <?= $workerRunning
                                ? 'Фоновый воркер возьмёт их по порядку.'
                                : 'Проверьте systemd-сервис stream-worker.' ?>
                        </div>
                    <?php endif; ?>
                    <div class="content-tree">
                        <?php foreach ($seriesFolders as $seriesFolder): ?>
                            <details class="content-folder series-folder" open>
                                <summary>
                                    <span class="folder-icon" aria-hidden="true">▰</span>
                                    <span class="folder-heading">
                                        <strong><?= e($seriesFolder['title']) ?></strong>
                                        <small>
                                            <?= count($seriesFolder['seasons']) ?> сез. ·
                                            <?= (int) $seriesFolder['total'] ?> сер. ·
                                            готово <?= (int) $seriesFolder['ready'] ?>/<?= (int) $seriesFolder['total'] ?>
                                        </small>
                                    </span>
                                    <span class="folder-chevron" aria-hidden="true">⌄</span>
                                </summary>
                                <details class="metadata-editor metadata-editor-series">
                                    <summary>Редактировать сериал</summary>
                                    <form method="post"
                                          action="/admin/series/<?= (int) $seriesFolder['id'] ?>">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <div class="metadata-form-grid">
                                            <label>
                                                Название сериала
                                                <input name="title" maxlength="180" required
                                                       value="<?= e($seriesFolder['title']) ?>">
                                            </label>
                                            <label class="metadata-description">
                                                Описание
                                                <textarea name="description" rows="3"
                                                          maxlength="5000"><?= e($seriesFolder['description']) ?></textarea>
                                            </label>
                                        </div>
                                        <button class="button" type="submit">Сохранить сериал</button>
                                    </form>
                                </details>
                                <div class="series-seasons">
                                    <?php foreach ($seriesFolder['seasons'] as $seasonNumber => $season): ?>
                                        <details class="content-folder season-folder" open>
                                            <summary>
                                                <span class="folder-icon folder-icon-small" aria-hidden="true">▰</span>
                                                <span class="folder-heading">
                                                    <strong>
                                                        Сезон <?= (int) $seasonNumber ?>
                                                        <?= $season['title'] !== '' ? ' · ' . e($season['title']) : '' ?>
                                                    </strong>
                                                    <small><?= count($season['episodes']) ?> сер.</small>
                                                </span>
                                                <span class="folder-chevron" aria-hidden="true">⌄</span>
                                            </summary>
                                            <details class="metadata-editor metadata-editor-season">
                                                <summary>Редактировать сезон</summary>
                                                <form method="post"
                                                      action="/admin/series/<?= (int) $seriesFolder['id'] ?>/seasons/<?= (int) $seasonNumber ?>">
                                                    <input type="hidden" name="_token"
                                                           value="<?= e(csrf_token()) ?>">
                                                    <div class="metadata-form-grid metadata-form-grid-season">
                                                        <label>
                                                            Номер сезона
                                                            <input name="season_number" type="number" min="1" required
                                                                   value="<?= (int) $seasonNumber ?>">
                                                        </label>
                                                        <label>
                                                            Название сезона
                                                            <input name="title" maxlength="180"
                                                                   placeholder="Необязательно"
                                                                   value="<?= e($season['title']) ?>">
                                                        </label>
                                                        <label class="metadata-description">
                                                            Описание
                                                            <textarea name="description" rows="3"
                                                                      maxlength="5000"><?= e($season['description']) ?></textarea>
                                                        </label>
                                                    </div>
                                                    <button class="button" type="submit">Сохранить сезон</button>
                                                </form>
                                            </details>
                                            <div class="season-episodes">
                                                <?php foreach ($season['episodes'] as $video): ?>
                                                    <?php require __DIR__ . '/admin-video-row.php'; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </details>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endforeach; ?>

                        <?php if ($standaloneVideos): ?>
                            <section class="standalone-content">
                                <div class="standalone-heading">
                                    <span class="folder-icon" aria-hidden="true">▰</span>
                                    <div>
                                        <strong>Отдельные видео</strong>
                                        <small><?= count($standaloneVideos) ?> видео</small>
                                    </div>
                                </div>
                                <div class="admin-list">
                                    <?php foreach ($standaloneVideos as $video): ?>
                                        <?php require __DIR__ . '/admin-video-row.php'; ?>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

        <?php elseif ($tab === 'player'): ?>
            <?php
            $accentColor = strtolower(trim((string) ($settings['player_accent_color'] ?? '#ff4e63')));
            if (!preg_match('/^#[0-9a-f]{6}$/', $accentColor)) {
                $accentColor = '#ff4e63';
            }
            ?>
            <section class="panel settings-panel">
                <div class="settings-preview">
                    <div class="mini-player" id="player-accent-preview" style="--mini-accent: <?= e($accentColor) ?>">
                        <div class="mini-play">▶</div>
                        <div class="mini-timeline"><span></span></div>
                        <div class="mini-controls">▶　🔊 <b>Авто</b>　⛶</div>
                    </div>
                    <div>
                        <p class="eyebrow">Предпросмотр</p>
                        <h2>Детский плеер</h2>
                        <p class="muted">Цвет применяется к кнопкам, таймлайну и активным элементам.</p>
                    </div>
                </div>

                <form method="post" action="/admin/settings/player" class="settings-form">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <label>
                        Название плеера
                        <input name="player_brand_name" maxlength="30"
                               value="<?= e($settings['player_brand_name'] ?? 'KidsTub') ?>">
                    </label>
                    <div class="form-grid">
                        <label>
                            Цвет плеера
                            <span class="color-field">
                                <input id="player-accent-color" name="player_accent_color" type="color"
                                       value="<?= e($accentColor) ?>"
                                       aria-label="Выбор цвета плеера">
                                <input id="player-accent-hex" type="text" maxlength="7"
                                       value="<?= e($accentColor) ?>"
                                       pattern="#[0-9A-Fa-f]{6}"
                                       aria-label="HEX-код цвета" readonly>
                            </span>
                        </label>
                        <label>
                            Громкость по умолчанию, %
                            <input name="player_default_volume" type="number" min="0" max="100"
                                   value="<?= (int) $settings['player_default_volume'] ?>">
                        </label>
                        <label>
                            Шаг перемотки, сек.
                            <input name="player_seek_step" type="number" min="5" max="60"
                                   value="<?= (int) $settings['player_seek_step'] ?>">
                        </label>
                        <label>
                            Интервал превью, сек.
                            <input name="player_preview_interval" type="number" min="5" max="60"
                                   value="<?= (int) $settings['player_preview_interval'] ?>">
                        </label>
                        <label>
                            Задержка следующей серии, сек.
                            <input name="player_next_delay" type="number" min="0" max="30"
                                   value="<?= (int) $settings['player_next_delay'] ?>">
                        </label>
                    </div>
                    <label class="toggle-row">
                        <input name="player_autoplay_next" type="checkbox" value="1"
                            <?= $settings['player_autoplay_next'] === '1' ? 'checked' : '' ?>>
                        <span><strong>Автоматически включать следующую серию</strong>
                            <small>После обратного отсчёта в конце видео</small></span>
                    </label>
                    <div class="player-feature-grid">
                        <?php
                        $featureLabels = [
                            'player_show_quality' => ['Выбор качества', 'Авто, 480p, 720p, 1080p'],
                            'player_show_volume' => ['Регулятор громкости', 'Кнопка звука и ползунок'],
                            'player_show_fullscreen' => ['Полноэкранный режим', 'Кнопка в панели управления'],
                            'player_show_next' => ['Следующая серия', 'Кнопка и финальный экран'],
                            'player_show_preview' => ['Превью таймлайна', 'Кадр при наведении'],
                        ];
                        ?>
                        <?php foreach ($featureLabels as $key => [$label, $help]): ?>
                            <label class="toggle-row">
                                <input name="<?= e($key) ?>" type="checkbox" value="1"
                                    <?= ($settings[$key] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span><strong><?= e($label) ?></strong><small><?= e($help) ?></small></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button class="button" type="submit">Сохранить настройки плеера</button>
                </form>
            </section>
            <script>
            (() => {
                const color = document.getElementById('player-accent-color');
                const hex = document.getElementById('player-accent-hex');
                const preview = document.getElementById('player-accent-preview');
                if (!color || !hex || !preview) return;
                const sync = () => {
                    const value = color.value.toLowerCase();
                    hex.value = value;
                    preview.style.setProperty('--mini-accent', value);
                };
                color.addEventListener('input', sync);
                color.addEventListener('change', sync);
                sync();
            })();
            </script>

        <?php elseif ($tab === 'storage'): ?>
            <section class="panel settings-panel">
                <div class="panel-title">
                    <div>
                        <h2>Хранилище видео</h2>
                        <p class="muted">Настройка применяется к новым загрузкам. Старые видео сохраняют свой профиль.</p>
                    </div>
                    <span class="storage-driver-badge"><?= e(strtoupper($settings['storage_driver'] ?? 'local')) ?></span>
                </div>

                <form method="post" action="/admin/settings/storage" class="settings-form" id="storage-settings-form">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <label>
                        Тип хранилища
                        <select name="storage_driver" id="storage-driver">
                            <option value="local" <?= ($settings['storage_driver'] ?? 'local') === 'local' ? 'selected' : '' ?>>
                                Локальный диск
                            </option>
                            <option value="network" <?= ($settings['storage_driver'] ?? '') === 'network' ? 'selected' : '' ?>>
                                Сетевой каталог (NFS / SMB mount)
                            </option>
                            <option value="s3" <?= ($settings['storage_driver'] ?? '') === 's3' ? 'selected' : '' ?>>
                                Amazon S3 / S3-compatible
                            </option>
                        </select>
                    </label>

                    <div data-storage-fields="filesystem">
                        <div class="storage-info">
                            Для сетевого хранилища сначала смонтируйте NFS/SMB в Ubuntu,
                            например в <code>/mnt/kidstub</code>. FFmpeg работает с ним как с обычным каталогом.
                        </div>
                        <label>
                            Каталог исходных MP4
                            <input name="storage_source_path"
                                   value="<?= e($settings['storage_source_path'] ?? '') ?>"
                                   placeholder="/mnt/kidstub/source">
                        </label>
                        <label>
                            Каталог готового HLS
                            <input name="storage_media_path"
                                   value="<?= e($settings['storage_media_path'] ?? '') ?>"
                                   placeholder="/mnt/kidstub/media">
                        </label>
                    </div>

                    <div data-storage-fields="s3">
                        <div class="storage-info">
                            Credentials не сохраняются в БД. Используйте IAM role, переменные
                            <code>AWS_ACCESS_KEY_ID</code>/<code>AWS_SECRET_ACCESS_KEY</code>
                            или профиль AWS пользователя <code>www-data</code>.
                        </div>
                        <div class="form-grid">
                            <label>
                                Bucket
                                <input name="storage_s3_bucket"
                                       value="<?= e($settings['storage_s3_bucket'] ?? '') ?>"
                                       placeholder="kidstub-video">
                            </label>
                            <label>
                                Регион
                                <input name="storage_s3_region"
                                       value="<?= e($settings['storage_s3_region'] ?? 'us-east-1') ?>">
                            </label>
                            <label>
                                Префикс
                                <input name="storage_s3_prefix"
                                       value="<?= e($settings['storage_s3_prefix'] ?? 'kidstub') ?>">
                            </label>
                            <label>
                                Endpoint (необязательно)
                                <input name="storage_s3_endpoint"
                                       value="<?= e($settings['storage_s3_endpoint'] ?? '') ?>"
                                       placeholder="https://s3.example.local">
                            </label>
                        </div>
                        <label class="toggle-row">
                            <input name="storage_s3_path_style" type="checkbox" value="1"
                                <?= ($settings['storage_s3_path_style'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <span><strong>Path-style endpoint</strong>
                                <small>Для MinIO и некоторых S3-compatible систем</small></span>
                        </label>
                    </div>

                    <label>
                        Публичный URL готового HLS
                        <input name="storage_public_url"
                               value="<?= e($settings['storage_public_url'] ?? '/media') ?>"
                               placeholder="https://cdn.example.com/kidstub/media">
                        <small class="field-help">
                            Для локального пути обычно <code>/media</code>; для NAS нужен Nginx alias;
                            для S3 — публичный bucket или CloudFront URL.
                        </small>
                    </label>
                    <button class="button" type="submit">Сохранить настройки хранилища</button>
                </form>
            </section>
            <script>
            (() => {
                const driver = document.getElementById('storage-driver');
                const sync = () => {
                    const isS3 = driver.value === 's3';
                    document.querySelector('[data-storage-fields="filesystem"]').hidden = isS3;
                    document.querySelector('[data-storage-fields="s3"]').hidden = !isS3;
                };
                driver.addEventListener('change', sync);
                sync();
            })();
            </script>

        <?php else: ?>
            <section class="panel settings-panel">
                <h2>Основные настройки сайта</h2>
                <form method="post" action="/admin/settings/site" class="settings-form">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <label>
                        Название сайта
                        <input name="site_title" maxlength="80" required
                               value="<?= e($settings['site_title']) ?>">
                    </label>
                    <label>
                        Короткое описание
                        <textarea name="site_tagline" maxlength="180" rows="3"><?= e($settings['site_tagline']) ?></textarea>
                    </label>
                    <button class="button" type="submit">Сохранить настройки сайта</button>
                </form>
            </section>
        <?php endif; ?>
    </main>

    <aside class="admin-sidebar">
        <p class="sidebar-title">Настройки и управление</p>
        <nav class="admin-nav">
            <a href="/admin?tab=content" class="<?= $tab === 'content' ? 'is-active' : '' ?>">
                <span>▤</span><div><strong>Контент</strong><small>Видео и сериалы</small></div>
            </a>
            <a href="/admin?tab=player" class="<?= $tab === 'player' ? 'is-active' : '' ?>">
                <span>▶</span><div><strong>Детский плеер</strong><small>Управление и цвета</small></div>
            </a>
            <a href="/admin?tab=storage" class="<?= $tab === 'storage' ? 'is-active' : '' ?>">
                <span>▰</span><div><strong>Хранилище</strong><small>Локально, NAS или S3</small></div>
            </a>
            <a href="/admin?tab=site" class="<?= $tab === 'site' ? 'is-active' : '' ?>">
                <span>⚙</span><div><strong>Сайт</strong><small>Название и описание</small></div>
            </a>
        </nav>
        <div class="sidebar-summary">
            <span>Название</span><strong><?= e($settings['site_title']) ?></strong>
            <span>Цвет плеера</span>
            <i style="background: <?= e(preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($settings['player_accent_color'] ?? '')) ? $settings['player_accent_color'] : '#ff4e63') ?>"></i>
            <span>Автосерия</span>
            <strong><?= $settings['player_autoplay_next'] === '1' ? 'Включена' : 'Выключена' ?></strong>
            <span>Хранилище</span>
            <strong><?= e(strtoupper($settings['storage_driver'] ?? 'local')) ?></strong>
            <span>Воркер</span>
            <strong class="<?= $workerRunning ? 'text-success' : 'text-danger' ?>">
                <?= $workerRunning ? 'Работает' : 'Остановлен' ?>
            </strong>
        </div>
        <a class="sidebar-site-link" href="/" target="_blank">Перейти на сайт ↗</a>
    </aside>
</div>

<?php if ($tab === 'content'): ?>
<script>
(() => {
    const labels = {queued: 'В очереди', processing: 'Конвертируется', ready: 'Готово', failed: 'Ошибка'};
    async function refreshStatuses() {
        try {
            const response = await fetch('/admin/videos/status', {
                headers: {'Accept': 'application/json'},
                cache: 'no-store'
            });
            if (!response.ok) return;
            const data = await response.json();
            data.videos.forEach(video => {
                const row = document.querySelector(`[data-video-id="${video.id}"]`);
                if (!row) return;
                const previousStatus = row.dataset.currentStatus;
                row.dataset.currentStatus = video.status;
                const badge = row.querySelector('[data-status-badge]');
                badge.className = `status status-${video.status}`;
                badge.textContent = labels[video.status] || video.status;
                row.querySelector('.progress-track span').style.width = `${video.progress}%`;
                let detail = 'Обработка остановлена';
                if (video.status === 'queued') detail = `Ожидает запуска · позиция ${video.queue_position}`;
                if (video.status === 'processing') detail = `${video.processing_stage || 'Конвертация'} · ${video.progress}%`;
                if (video.status === 'ready') detail = 'Адаптивный HLS готов · 100%';
                row.querySelector('.progress-text').textContent = detail;

                if (previousStatus !== video.status && ['ready', 'failed'].includes(video.status)) {
                    const hasDraft = [...document.querySelector('.upload-form').elements].some(element =>
                        ['text', 'textarea', 'file'].includes(element.type) && element.value
                    );
                    if (!hasDraft) window.location.reload();
                }
            });
        } catch (_) {}
    }
    setInterval(refreshStatuses, 2000);
})();
</script>
<?php endif; ?>
