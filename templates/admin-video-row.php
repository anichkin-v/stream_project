<?php
$queuePosition = $queuePositions[(int) $video['id']] ?? 0;
$videoQualities = json_decode((string) ($video['qualities_json'] ?? '[]'), true) ?: [];
?>
<article class="admin-video" data-video-id="<?= (int) $video['id'] ?>"
         data-current-status="<?= e($video['status']) ?>">
    <div class="admin-video-main">
        <div class="admin-video-title-row">
            <?php if ($video['series_id'] !== null): ?>
                <span class="episode-number">E<?= (int) $video['episode_number'] ?></span>
            <?php endif; ?>
            <h3><?= e($video['title']) ?></h3>
            <?php foreach ($videoQualities as $quality): ?>
                <span class="quality-chip"><?= e($quality['label']) ?></span>
            <?php endforeach; ?>
        </div>
        <p class="muted"><?= e($video['original_name']) ?> · <?= e($video['created_at']) ?></p>
        <div class="conversion-info">
            <div class="progress-track" aria-label="Прогресс конвертации">
                <span style="width: <?= (int) $video['progress'] ?>%"></span>
            </div>
            <p class="progress-text">
                <?php if ($video['status'] === 'queued'): ?>
                    Ожидает запуска · позиция <?= $queuePosition ?>
                <?php elseif ($video['status'] === 'processing'): ?>
                    <?= e($video['processing_stage'] ?: 'Конвертация') ?> ·
                    <strong><?= (int) $video['progress'] ?>%</strong>
                <?php elseif ($video['status'] === 'ready'): ?>
                    Адаптивный HLS готов · <strong>100%</strong>
                <?php else: ?>
                    Обработка остановлена
                <?php endif; ?>
            </p>
        </div>
        <?php if ($video['error_message']): ?>
            <details class="error-details">
                <summary>Ошибка FFmpeg</summary>
                <pre><?= e($video['error_message']) ?></pre>
            </details>
        <?php endif; ?>
        <details class="metadata-editor metadata-editor-video">
            <summary>
                Редактировать <?= $video['series_id'] !== null ? 'серию' : 'видео' ?>
            </summary>
            <form method="post" action="/admin/videos/<?= (int) $video['id'] ?>">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <div class="metadata-form-grid <?= $video['series_id'] !== null ? 'metadata-form-grid-episode' : '' ?>">
                    <label>
                        Название
                        <input name="title" maxlength="180" required value="<?= e($video['title']) ?>">
                    </label>
                    <?php if ($video['series_id'] !== null): ?>
                        <label>
                            Номер сезона
                            <input name="season_number" type="number" min="1" required
                                   value="<?= (int) $video['season_number'] ?>">
                        </label>
                        <label>
                            Номер серии
                            <input name="episode_number" type="number" min="1" required
                                   value="<?= (int) $video['episode_number'] ?>">
                        </label>
                    <?php endif; ?>
                    <label class="metadata-description">
                        Описание
                        <textarea name="description" rows="3"
                                  maxlength="5000"><?= e($video['description']) ?></textarea>
                    </label>
                </div>
                <button class="button" type="submit">
                    Сохранить <?= $video['series_id'] !== null ? 'серию' : 'видео' ?>
                </button>
            </form>
        </details>
    </div>
    <div class="admin-actions">
        <span class="status status-<?= e($video['status']) ?>" data-status-badge>
            <?= e($statusLabels[$video['status']] ?? $video['status']) ?>
        </span>
        <?php if ($video['status'] === 'ready'): ?>
            <a class="button button-secondary" href="/watch/<?= (int) $video['id'] ?>">Открыть</a>
            <form method="post" action="/admin/videos/<?= (int) $video['id'] ?>/reprocess"
                  onsubmit="return confirm('Перекодировать видео в адаптивные качества?')">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button class="button button-secondary" type="submit">Перекодировать</button>
            </form>
        <?php elseif ($video['status'] === 'failed'): ?>
            <form method="post" action="/admin/videos/<?= (int) $video['id'] ?>/retry">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button class="button button-secondary" type="submit">Повторить</button>
            </form>
        <?php endif; ?>
        <?php if ($video['status'] !== 'processing'): ?>
            <form method="post" action="/admin/videos/<?= (int) $video['id'] ?>/delete"
                  onsubmit="return confirm('Удалить видео и все его файлы?')">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button class="button button-danger" type="submit">Удалить</button>
            </form>
        <?php endif; ?>
    </div>
</article>
