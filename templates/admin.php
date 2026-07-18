<?php
$statusLabels = [
    'queued' => 'В очереди',
    'processing' => 'Конвертируется',
    'ready' => 'Готово',
    'failed' => 'Ошибка',
];
$queuePosition = 0;
?>
<div class="admin-heading">
    <div>
        <p class="eyebrow">Администрирование</p>
        <h1>Управление видео</h1>
    </div>
    <a class="button button-secondary" href="/">Открыть сайт для детей ↗</a>
</div>

<section class="panel">
    <h2>Добавить MP4</h2>
    <form method="post" action="/admin/videos" enctype="multipart/form-data" class="upload-form">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <label>
            Название
            <input name="title" maxlength="180" required>
        </label>
        <label>
            Описание
            <textarea name="description" rows="4"></textarea>
        </label>
        <label>
            Видео MP4 (до <?= e(format_bytes($maxUpload)) ?>)
            <input name="video" type="file" accept="video/mp4,.mp4" required>
        </label>
        <button class="button" type="submit">Загрузить и поставить в очередь</button>
    </form>
</section>

<section class="panel">
    <div class="panel-title">
        <div>
            <h2>Контент</h2>
            <p class="muted">Статусы обновляются автоматически</p>
        </div>
        <span class="live-indicator"><i></i> Онлайн</span>
    </div>
    <?php if (!$videos): ?>
        <p class="muted">Видео ещё не загружались.</p>
    <?php else: ?>
        <?php if (array_filter($videos, static fn (array $video): bool => $video['status'] === 'queued')): ?>
            <div class="worker-hint">
                <strong>Есть задания в очереди.</strong>
                <?php if (!empty($workerRunning)): ?>
                    Фоновый воркер работает и возьмёт их по порядку.
                <?php else: ?>
                    Служба конвертации сейчас не запущена. Проверьте systemd-сервис
                    <code>stream-worker</code>.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="admin-list">
            <?php foreach ($videos as $video): ?>
                <?php if ($video['status'] === 'queued') {
                    $queuePosition++;
                } ?>
                <article class="admin-video" data-video-id="<?= (int) $video['id'] ?>"
                         data-current-status="<?= e($video['status']) ?>">
                    <div class="admin-video-main">
                        <h3><?= e($video['title']) ?></h3>
                        <p class="muted"><?= e($video['original_name']) ?> · <?= e($video['created_at']) ?></p>
                        <div class="conversion-info">
                            <div class="progress-track" aria-label="Прогресс конвертации">
                                <span style="width: <?= (int) $video['progress'] ?>%"></span>
                            </div>
                            <p class="progress-text">
                                <?php if ($video['status'] === 'queued'): ?>
                                    Ожидает запуска · позиция <?= $queuePosition ?> в очереди
                                <?php elseif ($video['status'] === 'processing'): ?>
                                    <?= e($video['processing_stage'] ?: 'Конвертация') ?> ·
                                    <strong><?= (int) $video['progress'] ?>%</strong>
                                <?php elseif ($video['status'] === 'ready'): ?>
                                    Обработка завершена · <strong>100%</strong>
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
                    </div>
                    <div class="admin-actions">
                        <span class="status status-<?= e($video['status']) ?>" data-status-badge>
                            <?= e($statusLabels[$video['status']] ?? $video['status']) ?>
                        </span>
                        <?php if ($video['status'] === 'ready'): ?>
                            <a class="button button-secondary" href="/watch/<?= (int) $video['id'] ?>">Открыть</a>
                        <?php elseif ($video['status'] === 'failed'): ?>
                            <form method="post" action="/admin/videos/<?= (int) $video['id'] ?>/retry">
                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                <button class="button button-secondary" type="submit">Повторить</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($video['status'] !== 'processing'): ?>
                            <form method="post" action="/admin/videos/<?= (int) $video['id'] ?>/delete"
                                  onsubmit="return confirm('Удалить видео и все его файлы? Это действие нельзя отменить.')">
                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                <button class="button button-danger" type="submit" title="Удалить видео">
                                    Удалить
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<script>
(() => {
    const labels = {
        queued: 'В очереди',
        processing: 'Конвертируется',
        ready: 'Готово',
        failed: 'Ошибка'
    };

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
                if (video.status === 'queued') {
                    detail = `Ожидает запуска · позиция ${video.queue_position} в очереди`;
                } else if (video.status === 'processing') {
                    detail = `${video.processing_stage || 'Конвертация'} · ${video.progress}%`;
                } else if (video.status === 'ready') {
                    detail = 'Обработка завершена · 100%';
                }
                row.querySelector('.progress-text').textContent = detail;

                if (previousStatus !== video.status && ['ready', 'failed'].includes(video.status)) {
                    const form = document.querySelector('.upload-form');
                    const hasDraft = [...form.elements].some(element =>
                        ['text', 'textarea', 'file'].includes(element.type) && element.value
                    );
                    if (!hasDraft) window.location.reload();
                }
            });
        } catch (_) {
            // Следующая попытка произойдёт автоматически.
        }
    }

    setInterval(refreshStatuses, 2000);
})();
</script>
