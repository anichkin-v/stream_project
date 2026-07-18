#!/usr/bin/env bash
#
# Установка и запуск проекта на Ubuntu 24.04 (Nginx + PHP-FPM + systemd-воркер).
# Запускать из корня проекта:
#
#   sudo bash install.sh
#
set -euo pipefail

PROJECT_DIR="${PROJECT_DIR:-/home/web_deploy/web/public/stream}"
DEPLOY_USER="${DEPLOY_USER:-www-data}"
DEPLOY_GROUP="${DEPLOY_GROUP:-$DEPLOY_USER}"
PHP_VERSION="${PHP_VERSION:-8.4}"
ADMIN_LOGIN="${ADMIN_LOGIN:-admin}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ $EUID -ne 0 ]]; then
    echo "Запустите скрипт с правами root: sudo bash install.sh" >&2
    exit 1
fi

echo "==> Установка пакетов"
apt-get update
apt-get install -y \
    nginx \
    "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-sqlite3" \
    "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-curl" \
    ffmpeg
# Composer нужен только для Amazon S3 (INSTALL_S3=1).
if [[ "${INSTALL_S3:-0}" == "1" ]]; then
    apt-get install -y composer
fi

echo "==> Синхронизация файлов проекта в $PROJECT_DIR"
mkdir -p "$PROJECT_DIR"
if [[ "$SCRIPT_DIR" != "$PROJECT_DIR" ]]; then
    if command -v rsync >/dev/null 2>&1; then
        rsync -a --delete \
            --exclude '.git' \
            --exclude 'storage/database.sqlite*' \
            --exclude 'storage/uploads/*' \
            --exclude 'storage/worker.*' \
            --exclude 'public/media/*' \
            --exclude 'vendor' \
            "$SCRIPT_DIR"/ "$PROJECT_DIR"/
    else
        cp -a "$SCRIPT_DIR"/. "$PROJECT_DIR"/
    fi
fi

echo "==> Подготовка каталогов и прав"
mkdir -p "$PROJECT_DIR/storage/uploads" "$PROJECT_DIR/storage/sessions" "$PROJECT_DIR/public/media"
# Для local/network vendor не нужен — удаляем битый/лишний каталог.
if [[ "${INSTALL_S3:-0}" != "1" ]]; then
    rm -rf "$PROJECT_DIR/vendor"
fi
chown -R "$DEPLOY_USER:$DEPLOY_GROUP" "$PROJECT_DIR"
chmod -R 775 "$PROJECT_DIR/storage" "$PROJECT_DIR/public/media"

if [[ "${INSTALL_S3:-0}" == "1" ]]; then
    echo "==> Установка PHP-зависимостей для S3"
    sudo -u "$DEPLOY_USER" env COMPOSER_HOME=/tmp/kidstub-composer \
        composer install --working-dir="$PROJECT_DIR" --no-dev --optimize-autoloader --no-interaction
else
    echo "==> Composer/vendor пропущены (локальное хранилище). Для S3: INSTALL_S3=1 bash install.sh"
fi

# Nginx/PHP-FPM (www-data) должны иметь право прохода к каталогу проекта в /home.
chmod o+x /home /home/web_deploy /home/web_deploy/web /home/web_deploy/web/public 2>/dev/null || true

echo "==> Проверка SQLite"
DB_FILE="$PROJECT_DIR/storage/database.sqlite"
systemctl stop stream-worker 2>/dev/null || true
if [[ -f "$DB_FILE" ]]; then
    if ! sudo -u "$DEPLOY_USER" php -r '
        try {
            $pdo = new PDO("sqlite:" . $argv[1], null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->query("SELECT count(*) FROM sqlite_master")->fetchColumn();
            exit(0);
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(1);
        }
    ' "$DB_FILE"; then
        STAMP="$(date +%Y%m%d-%H%M%S)"
        echo "    База повреждена — сохраняю бэкап и создаю новую."
        mv "$DB_FILE" "$DB_FILE.broken-$STAMP"
        rm -f "$DB_FILE-wal" "$DB_FILE-shm"
        chown "$DEPLOY_USER:$DEPLOY_GROUP" "$DB_FILE.broken-$STAMP" 2>/dev/null || true
    else
        echo "    База в порядке."
    fi
fi

echo "==> Инициализация базы данных"
sudo -u "$DEPLOY_USER" php "$PROJECT_DIR/bin/init-db.php"

ADMIN_COUNT="$(sudo -u "$DEPLOY_USER" php -r '
    $app = require $argv[1] . "/src/bootstrap.php";
    if (!is_array($app) || !(($app["pdo"] ?? null) instanceof PDO)) {
        fwrite(STDERR, "bootstrap failed\n");
        exit(2);
    }
    echo (int) $app["pdo"]->query("SELECT COUNT(*) FROM admins")->fetchColumn();
' "$PROJECT_DIR")"

if [[ "$ADMIN_COUNT" -gt 0 ]]; then
    echo "    Администратор уже существует, пропускаю создание."
else
    echo "==> Создание администратора «$ADMIN_LOGIN»"
    if [[ -n "${ADMIN_PASSWORD:-}" ]]; then
        sudo -u "$DEPLOY_USER" ADMIN_PASSWORD="$ADMIN_PASSWORD" \
            php "$PROJECT_DIR/bin/create-admin.php" "$ADMIN_LOGIN"
    else
        read -r -s -p "Пароль (минимум 10 символов): " ADMIN_PASSWORD_INPUT
        echo
        sudo -u "$DEPLOY_USER" ADMIN_PASSWORD="$ADMIN_PASSWORD_INPUT" \
            php "$PROJECT_DIR/bin/create-admin.php" "$ADMIN_LOGIN"
        unset ADMIN_PASSWORD_INPUT
    fi
fi

echo "==> Установка конфигураций PHP-FPM и Nginx"
cp "$SCRIPT_DIR/deploy/php-fpm.ini" "/etc/php/${PHP_VERSION}/fpm/conf.d/99-stream-project.ini"

sed -e "s#/home/web_deploy/web/public/stream#${PROJECT_DIR}#g" \
    -e "s#/run/php/php8.4-fpm.sock#/run/php/php${PHP_VERSION}-fpm.sock#g" \
    "$SCRIPT_DIR/deploy/nginx.conf" > /etc/nginx/sites-available/stream-project
ln -sfn /etc/nginx/sites-available/stream-project /etc/nginx/sites-enabled/stream-project
rm -f /etc/nginx/sites-enabled/default

echo "==> Установка systemd-воркера"
sed -e "s#/home/web_deploy/web/public/stream#${PROJECT_DIR}#g" \
    -e "s/php8.4-fpm/php${PHP_VERSION}-fpm/g" \
    -e "s/^User=www-data/User=${DEPLOY_USER}/" \
    -e "s/^Group=www-data/Group=${DEPLOY_GROUP}/" \
    "$SCRIPT_DIR/deploy/stream-worker.service" > /etc/systemd/system/stream-worker.service

echo "==> Проверка и перезапуск сервисов"
nginx -t
systemctl daemon-reload
systemctl restart "php${PHP_VERSION}-fpm" nginx
systemctl enable --now stream-worker
systemctl restart stream-worker

echo
echo "Готово. Проверьте статус:"
echo "  systemctl status nginx php${PHP_VERSION}-fpm stream-worker"
echo "Откройте админку: http://<IP-или-домен>/admin"
