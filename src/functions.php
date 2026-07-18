<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_pjax_request(): bool
{
    return (($_GET['pjax'] ?? '') === '1')
        || (($_SERVER['HTTP_X_PJAX'] ?? '') === '1');
}

function episode_option_label(array $episode): string
{
    $episodeNumber = (int) ($episode['episode_number'] ?? 0);
    $episodeTitle = trim((string) ($episode['title'] ?? ''));
    $isGenericTitle = $episodeTitle === ''
        || $episodeTitle === (string) $episodeNumber
        || preg_match('/^серия\s*' . $episodeNumber . '$/iu', $episodeTitle) === 1
        || preg_match('/^серия\s*\d+$/iu', $episodeTitle) === 1;

    return $episodeNumber . ' · ' . ($isGenericTitle ? 'Серия' : $episodeTitle);
}

function asset_url(string $path): string
{
    global $config;

    $publicPath = $config['paths']['root'] . '/public/' . ltrim($path, '/');
    $version = is_file($publicPath) ? (string) filemtime($publicPath) : '1';

    return '/' . ltrim($path, '/') . '?v=' . rawurlencode($version);
}

function render(string $template, array $data = []): never
{
    global $config;

    extract($data, EXTR_SKIP);
    ob_start();
    require $config['paths']['templates'] . '/' . $template . '.php';
    $content = (string) ob_get_clean();
    require $config['paths']['templates'] . '/layout.php';
    exit;
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function is_admin(): bool
{
    return isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id']);
}

function require_admin(): void
{
    if (!is_admin()) {
        redirect('/admin/login');
    }
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Сессия формы истекла. Обновите страницу и повторите.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $flashes;
}

function route_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($path) ? rawurldecode($path) : '/';

    return '/' . trim($path, '/');
}

function format_bytes(int $bytes): string
{
    $units = ['Б', 'КБ', 'МБ', 'ГБ'];
    $value = max(0, $bytes);
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return number_format($value, $unit === 0 ? 0 : 1, ',', ' ') . ' ' . $units[$unit];
}

function format_duration(?float $seconds): string
{
    if ($seconds === null || $seconds <= 0) {
        return '';
    }

    $total = (int) round($seconds);
    $hours = intdiv($total, 3600);
    $minutes = intdiv($total % 3600, 60);
    $remainingSeconds = $total % 60;

    return $hours > 0
        ? sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds)
        : sprintf('%d:%02d', $minutes, $remainingSeconds);
}

function delete_tree(string $directory): bool
{
    if (!is_dir($directory)) {
        return true;
    }

    $success = true;
    foreach (new FilesystemIterator($directory) as $item) {
        if ($item->isDir() && !$item->isLink()) {
            $success = delete_tree($item->getPathname()) && $success;
        } else {
            $success = @unlink($item->getPathname()) && $success;
        }
    }

    return @rmdir($directory) && $success;
}

function load_settings(PDO $pdo): array
{
    $settings = [];
    foreach ($pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    return $settings;
}

function save_settings(PDO $pdo, array $settings): void
{
    $statement = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value, updated_at)
         VALUES (:key, :value, CURRENT_TIMESTAMP)
         ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            updated_at = CURRENT_TIMESTAMP'
    );
    foreach ($settings as $key => $value) {
        $statement->execute([
            'key' => (string) $key,
            'value' => (string) $value,
        ]);
    }
}
