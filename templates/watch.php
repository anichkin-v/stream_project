<article class="watch-page">
    <a class="back-link" href="/">← Все видео</a>
    <div class="player-shell">
        <video id="video-player" controls playsinline preload="metadata"
               poster="/media/<?= (int) $video['id'] ?>/poster.jpg"></video>
        <div id="player-error" class="player-error" hidden>Не удалось загрузить видео.</div>
    </div>
    <h1><?= e($video['title']) ?></h1>
    <?php if ($video['description'] !== ''): ?>
        <p class="description"><?= nl2br(e($video['description'])) ?></p>
    <?php endif; ?>
</article>

<script src="https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js"></script>
<script>
(() => {
    const player = document.getElementById('video-player');
    const errorBox = document.getElementById('player-error');
    const source = <?= json_encode('/media/' . $video['hls_path'], JSON_UNESCAPED_SLASHES) ?>;

    const showError = () => {
        errorBox.hidden = false;
    };

    if (player.canPlayType('application/vnd.apple.mpegurl')) {
        player.src = source;
        player.addEventListener('error', showError);
        return;
    }

    if (window.Hls && Hls.isSupported()) {
        const hls = new Hls({
            enableWorker: true,
            lowLatencyMode: false,
        });
        hls.loadSource(source);
        hls.attachMedia(player);
        hls.on(Hls.Events.ERROR, (_, data) => {
            if (data.fatal) {
                showError();
            }
        });
        return;
    }

    showError();
})();
</script>
