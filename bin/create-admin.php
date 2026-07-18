<?php

declare(strict_types=1);

[$config, $pdo] = require dirname(__DIR__) . '/src/bootstrap.php';

$username = trim((string) ($argv[1] ?? ''));
if ($username === '') {
    fwrite(STDERR, "Использование: php bin/create-admin.php <логин>\n");
    exit(1);
}

fwrite(STDOUT, 'Пароль: ');
$password = trim((string) fgets(STDIN));
if (mb_strlen($password) < 10) {
    fwrite(STDERR, "Пароль должен содержать не менее 10 символов.\n");
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
