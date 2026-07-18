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
            "$SCRIPT_DIR"/ "$PROJECT_DIR"/
    else
        cp -a "$SCRIPT_DIR"/. "$PROJECT_DIR"/
    fi
fi

echo "==> Подготовка каталогов и прав"
mkdir -p "$PROJECT_DIR/storage/uploads" "$PROJECT_DIR/public/media"
chown -R "$DEPLOY_USER:$DEPLOY_GROUP" "$PROJECT_DIR"
chmod -R 775 "$PROJECT_DIR/storage" "$PROJECT_DIR/public/media"

# Nginx/PHP-FPM (www-data) должны иметь право прохода к каталогу проекта в /home.
chmod o+x /home /home/web_deploy /home/web_deploy/web /home/web_deploy/web/public 2>/dev/null || true

echo "==> Инициализация базы данных"
sudo -u "$DEPLOY_USER" php "$PROJECT_DIR/bin/init-db.php"

if sudo -u "$DEPLOY_USER" php -r '
    [$c,$pdo]=require $argv[1]."/src/bootstrap.php";
    exit((int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn() > 0 ? 0 : 1);
' "$PROJECT_DIR" 2>/dev/null; then
    echo "    Администратор уже существует, пропускаю создание."
else
    echo "==> Создание администратора «$ADMIN_LOGIN» (введите пароль)"
    sudo -u "$DEPLOY_USER" php "$PROJECT_DIR/bin/create-admin.php" "$ADMIN_LOGIN"
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
