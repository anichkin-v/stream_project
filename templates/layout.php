<?php
/** @var string $content */
$flashes = take_flashes();
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? $config['app_name']) ?> — <?= e($config['app_name']) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('assets/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/player.css')) ?>">
</head>
<body>
<header class="site-header">
    <a class="brand" href="/"><span>▶</span> <?= e($config['app_name']) ?></a>
    <nav>
        <?php if (is_admin()): ?>
            <a href="/">Сайт для детей</a>
            <a href="/admin">Админ-панель</a>
            <form method="post" action="/admin/logout" class="inline-form">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button class="link-button" type="submit">Выйти</button>
            </form>
        <?php else: ?>
            <a class="admin-login-link" href="/admin/login">Вход для взрослых</a>
        <?php endif; ?>
    </nav>
</header>
<main class="container">
    <?php foreach ($flashes as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<footer>Безопасная видеотека для детей</footer>
</body>
</html>
