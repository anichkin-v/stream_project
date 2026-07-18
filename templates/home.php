<section class="hero">
    <div class="hero-content">
        <p class="eyebrow">Безопасное пространство</p>
        <h1>Смотри, узнавай,<br><span>расти с улыбкой</span></h1>
        <p>Добрые мультфильмы и познавательные видео, которые заранее выбрали взрослые.</p>
        <?php if ($videos): ?>
            <a class="button hero-button" href="#videos">Смотреть видео <span>→</span></a>
        <?php endif; ?>
    </div>
    <div class="hero-visual" aria-hidden="true">
        <div class="hero-orbit orbit-one">★</div>
        <div class="hero-orbit orbit-two">●</div>
        <div class="hero-play">▶</div>
    </div>
</section>

<section class="trust-strip" aria-label="Преимущества">
    <div><span>✓</span><strong>Проверено взрослыми</strong><small>Только выбранные видео</small></div>
    <div><span>♢</span><strong>Без рекламы</strong><small>Ничего лишнего</small></div>
    <div><span>☺</span><strong>Для всей семьи</strong><small>Добрый контент</small></div>
</section>

<?php if (!$videos): ?>
    <div class="empty-state public-empty">
        <div class="empty-illustration">▶</div>
        <h2>Первое видео уже готовится</h2>
        <p>Как только взрослый завершит обработку, оно появится здесь автоматически.</p>
        <?php if (is_admin()): ?>
            <a class="button button-secondary" href="/admin">Проверить обработку</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <section id="videos" class="catalog-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Наша коллекция</p>
                <h2>Новые видео</h2>
            </div>
            <label class="search-box">
                <span>⌕</span>
                <input id="video-search" type="search" placeholder="Найти видео" aria-label="Найти видео">
            </label>
        </div>
        <div class="video-grid" id="video-grid">
            <?php foreach ($videos as $video): ?>
                <a class="video-card" href="/watch/<?= (int) $video['id'] ?>"
                   data-title="<?= e(mb_strtolower($video['title'])) ?>">
                    <div class="video-preview">
                        <img src="/media/<?= (int) $video['id'] ?>/poster.jpg" alt="" loading="lazy"
                             onerror="this.remove()">
                        <span class="card-play">▶</span>
                        <?php if (format_duration((float) $video['duration_seconds']) !== ''): ?>
                            <span class="duration"><?= e(format_duration((float) $video['duration_seconds'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="video-card-body">
                        <h3><?= e($video['title']) ?></h3>
                        <?php if ($video['description'] !== ''): ?>
                            <p><?= e(mb_strimwidth($video['description'], 0, 120, '…')) ?></p>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <p id="search-empty" class="search-empty" hidden>По вашему запросу ничего не найдено.</p>
    </section>
    <script>
    (() => {
        const input = document.getElementById('video-search');
        const cards = [...document.querySelectorAll('#video-grid .video-card')];
        const empty = document.getElementById('search-empty');
        input.addEventListener('input', () => {
            const query = input.value.trim().toLocaleLowerCase('ru');
            let visible = 0;
            cards.forEach(card => {
                const matches = card.dataset.title.includes(query);
                card.hidden = !matches;
                if (matches) visible++;
            });
            empty.hidden = visible !== 0;
        });
    })();
    </script>
<?php endif; ?>
