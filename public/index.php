<?php

declare(strict_types=1);

[$config, $pdo] = require dirname(__DIR__) . '/src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = route_path();

if ($method === 'GET' && $path === '/') {
    $videos = $pdo->query(
        "SELECT id, title, description, hls_path, duration_seconds, created_at
         FROM videos WHERE status = 'ready' ORDER BY created_at DESC"
    )->fetchAll();
    render('home', ['title' => $config['app_name'], 'videos' => $videos]);
}

if ($method === 'GET' && preg_match('#^/watch/(\d+)$#', $path, $matches)) {
    $statement = $pdo->prepare(
        "SELECT id, title, description, hls_path, created_at
         FROM videos WHERE id = :id AND status = 'ready'"
    );
    $statement->execute(['id' => (int) $matches[1]]);
    $video = $statement->fetch();
    if (!$video) {
        http_response_code(404);
        render('error', ['title' => 'Видео не найдено', 'message' => 'Видео не найдено или ещё не готово.']);
    }
    render('watch', ['title' => $video['title'], 'video' => $video]);
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
    $videos = $pdo->query('SELECT * FROM videos ORDER BY created_at DESC')->fetchAll();
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

if ($method === 'POST' && $path === '/admin/videos') {
    require_admin();
    verify_csrf();

    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $file = $_FILES['video'] ?? null;

    if ($title === '' || mb_strlen($title) > 180) {
        flash('error', 'Укажите название длиной до 180 символов.');
        redirect('/admin');
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
    $destination = $config['paths']['uploads'] . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
        flash('error', 'Не удалось сохранить загруженный файл.');
        redirect('/admin');
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO videos (title, description, original_name, source_path)
             VALUES (:title, :description, :original_name, :source_path)'
        );
        $statement->execute([
            'title' => $title,
            'description' => $description,
            'original_name' => $originalName,
            'source_path' => $filename,
        ]);
    } catch (Throwable $exception) {
        @unlink($destination);
        throw $exception;
    }

    flash('success', 'Видео загружено и поставлено в очередь.');
    if (!ensure_worker_running($config)) {
        flash('error', 'Не удалось автоматически запустить воркер. Проверьте права на storage/ и PATH для PHP/FFmpeg.');
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

if ($method === 'POST' && preg_match('#^/admin/videos/(\d+)/delete$#', $path, $matches)) {
    require_admin();
    verify_csrf();
    $videoId = (int) $matches[1];

    $statement = $pdo->prepare(
        'SELECT id, title, source_path, status FROM videos WHERE id = :id'
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

    $sourcePath = $config['paths']['uploads'] . '/' . basename((string) $video['source_path']);
    $sourceDeleted = !is_file($sourcePath) || @unlink($sourcePath);
    $mediaDeleted = delete_tree($config['paths']['hls'] . '/' . $videoId);

    if (!$sourceDeleted || !$mediaDeleted) {
        flash('error', 'Запись удалена, но часть файлов не удалось очистить с диска.');
    } else {
        flash('success', 'Видео «' . $video['title'] . '» и его файлы удалены.');
    }
    redirect('/admin');
}

http_response_code(404);
render('error', ['title' => 'Страница не найдена', 'message' => 'Такой страницы не существует.']);
