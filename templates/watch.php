<?php
$qualities = json_decode((string) ($video['qualities_json'] ?? '[]'), true) ?: [];
foreach ($qualities as &$quality) {
    $quality['source'] = rtrim($video['media_base_url'], '/')
        . '/' . $quality['label'] . '/index.m3u8';
}
unset($quality);
$showQuality = ($settings['player_show_quality'] ?? '1') === '1';
$showVolume = ($settings['player_show_volume'] ?? '1') === '1';
$showFullscreen = ($settings['player_show_fullscreen'] ?? '1') === '1';
$showNext = ($settings['player_show_next'] ?? '1') === '1';
$showPreview = ($settings['player_show_preview'] ?? '1') === '1';
$brandName = $settings['player_brand_name'] ?? 'KidsTub';
$playerConfig = [
    'source' => $video['hls_url'],
    'previewUrl' => $showPreview ? $video['preview_vtt_url'] : null,
    'qualities' => $qualities,
    'accentColor' => $settings['player_accent_color'] ?? '#ff4e63',
    'defaultVolume' => (int) ($settings['player_default_volume'] ?? 80),
    'seekStep' => (int) ($settings['player_seek_step'] ?? 10),
    'autoplayNext' => ($settings['player_autoplay_next'] ?? '1') === '1',
    'nextDelay' => (int) ($settings['player_next_delay'] ?? 5),
    'nextUrl' => $showNext && $nextEpisode ? '/watch/' . (int) $nextEpisode['id'] : null,
    'showPreview' => $showPreview,
    'brandName' => $brandName,
];
?>
<article class="watch-page">
    <a class="back-link" href="/">← Все видео</a>

    <div class="kids-player" data-kids-player tabindex="0">
        <video playsinline preload="metadata"
               poster="<?= e($video['poster_url']) ?>"></video>

        <div class="player-title-bar">
            <div class="player-heading">
                <strong><?= e($video['series_title'] ?: $video['title']) ?></strong>
                <?php if ($video['series_title'] && $seasons): ?>
                    <div class="player-series-selectors">
                        <label>
                            <span>Сезон</span>
                            <select aria-label="Выбрать сезон"
                                    onchange="if (this.value) location.href = this.value">
                                <?php foreach ($seasons as $seasonNumber => $seasonEpisodes): ?>
                                    <option value="/watch/<?= (int) $seasonEpisodes[0]['id'] ?>"
                                        <?= (int) $video['season_number'] === (int) $seasonNumber ? 'selected' : '' ?>>
                                        <?= (int) $seasonNumber ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Серия</span>
                            <select aria-label="Выбрать серию"
                                    onchange="if (this.value) location.href = this.value">
                                <?php foreach ($seasons[(int) $video['season_number']] ?? [] as $episode): ?>
                                    <option value="/watch/<?= (int) $episode['id'] ?>"
                                        <?= (int) $video['id'] === (int) $episode['id'] ? 'selected' : '' ?>>
                                        <?= (int) $episode['episode_number'] ?> · <?= e($episode['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <small><?= e($video['title']) ?></small>
                <?php endif; ?>
            </div>
            <span class="player-safe-badge">
                <?= e($brandName) ?> · безопасно
            </span>
        </div>

        <button type="button" class="player-center-play" data-action="center-play"
                aria-label="Воспроизвести">
            <svg class="player-ico" viewBox="0 0 24 24" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                <path fill="currentColor" d="M8.75 5.25v13.5l10.25-6.75z"/>
            </svg>
        </button>
        <div class="player-buffer" aria-label="Загрузка"></div>
        <div class="player-error-message" data-role="error" hidden>
            <?= e($brandName) ?> не смог загрузить видео.
            Попробуйте обновить страницу.
        </div>

        <div class="player-controls">
            <div class="player-timeline">
                <span class="player-time-label" data-role="current-time">0:00</span>
                <div class="player-seek-wrap">
                    <div class="timeline-preview" data-role="preview" hidden>
                        <img src="" alt="" hidden>
                        <span>0:00</span>
                    </div>
                    <input class="player-seek" data-role="seek" type="range"
                           min="0" max="1000" value="0" step="1" aria-label="Перемотка">
                </div>
                <span class="player-time-label player-time-label--end" data-role="duration">0:00</span>
            </div>

            <div class="player-controls__left">
                <button type="button" class="player-btn player-btn--play" data-action="play"
                        aria-label="Воспроизвести" data-icon-slot="play"></button>
                <?php if ($nextEpisode && $showNext): ?>
                    <button type="button" class="player-btn player-btn--next" data-action="next"
                            title="Следующая серия" aria-label="Следующая серия"
                            data-icon-slot="next"></button>
                <?php endif; ?>
            </div>

            <div class="player-controls__center" aria-hidden="true">
                <span class="player-brand">
                    <i></i><b><?= e($brandName) ?></b>
                </span>
            </div>

            <div class="player-controls__right">
                <div class="player-volume" <?= $showVolume ? '' : 'hidden' ?>>
                    <button type="button" class="player-btn" data-action="mute"
                            aria-label="Звук" data-icon-slot="volume"></button>
                    <input class="player-volume-range" data-role="volume" type="range"
                           min="0" max="100" value="80" aria-label="Громкость">
                </div>
                <div class="player-settings" <?= $showQuality ? '' : 'hidden' ?>>
                    <button type="button" class="player-btn player-btn--settings" data-action="quality"
                            aria-label="Качество видео" data-icon-slot="settings"></button>
                    <span class="player-quality-badge" data-role="quality-badge" hidden>HD</span>
                    <div class="player-quality-menu" data-role="quality-menu" hidden></div>
                </div>
                <button type="button" class="player-btn" data-action="fullscreen"
                        aria-label="На весь экран" data-icon-slot="fullscreen"
                    <?= $showFullscreen ? '' : 'hidden' ?>></button>
            </div>
        </div>

        <?php if ($nextEpisode && $showNext): ?>
            <div class="next-episode-overlay" data-role="next-overlay" hidden>
                <div class="next-card">
                    <p>Дальше</p>
                    <h2><?= e($nextEpisode['title']) ?></h2>
                    <p>Следующая серия начнётся через
                        <strong data-role="next-countdown"><?= (int) ($settings['player_next_delay'] ?? 5) ?></strong> сек.
                    </p>
                    <div class="next-card-actions">
                        <button type="button" data-action="next">Смотреть сейчас</button>
                        <button type="button" class="next-cancel" data-action="cancel-next">Остаться</button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <h1><?= e($video['title']) ?></h1>
    <?php if ($video['series_title']): ?>
        <p class="muted">
            <?= e($video['series_title']) ?> · Сезон <?= (int) $video['season_number'] ?>
            · Серия <?= (int) $video['episode_number'] ?>
        </p>
    <?php endif; ?>
    <?php if ($video['description'] !== ''): ?>
        <p class="description"><?= nl2br(e($video['description'])) ?></p>
    <?php endif; ?>

</article>

<script id="kids-player-config" type="application/json"><?= json_encode(
    $playerConfig,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR,
) ?></script>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js"></script>
<script src="<?= e(asset_url('assets/player-icons.js')) ?>"></script>
<script src="<?= e(asset_url('assets/player.js')) ?>"></script>
