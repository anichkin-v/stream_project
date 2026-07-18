<?php

declare(strict_types=1);

['config' => $config, 'pdo' => $pdo] = require dirname(__DIR__) . '/src/bootstrap.php';

$username = trim((string) ($argv[1] ?? ''));
if ($username === '') {
    fwrite(STDERR, "Использование: php bin/create-admin.php <логин> [пароль]\n");
    fwrite(STDERR, "Пароль также можно передать через переменную ADMIN_PASSWORD.\n");
    exit(1);
}

$password = (string) ($argv[2] ?? getenv('ADMIN_PASSWORD') ?: '');
if ($password === '') {
    fwrite(STDOUT, 'Пароль: ');
    $password = trim((string) fgets(STDIN));
}

if (mb_strlen($password) < 10) {
    fwrite(STDERR, "Пароль должен содержать не менее 10 символов.\n");
    exit(1);
}

if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Ошибка: соединение с базой данных не инициализировано.\n");
    exit(1);
}

$statement = $pdo->prepare(
    'INSERT INTO admins (username, password_hash) VALUES (:username, :password)
     ON CONFLICT(username) DO UPDATE SET password_hash = excluded.password_hash'
);
$statement->execute([
    'username' => $username,
    'password' => password_hash($password, PASSWORD_DEFAULT),
]);

echo "Администратор «{$username}» создан или обновлён.\n";
