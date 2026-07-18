# Детская видеотека

Видеосервис на чистом PHP: администратор загружает MP4, системный воркер конвертирует его
на CPU в HLS, после чего видео появляется в детском каталоге.

## Установка на Ubuntu 24.04

Конфигурация рассчитана на PHP 8.3, который поставляется в Ubuntu 24.04:

```bash
sudo apt update
sudo apt install nginx php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-mbstring \
  php8.3-curl ffmpeg
sudo mkdir -p /var/www/stream_project
```

Скопируйте проект в `/var/www/stream_project`, затем подготовьте каталоги и базу:

```bash
cd /var/www/stream_project
sudo mkdir -p storage/uploads public/media
sudo chown -R www-data:www-data storage public/media
sudo chmod -R 775 storage public/media
sudo -u www-data php bin/init-db.php
sudo -u www-data php bin/create-admin.php admin
```

Если проект находится по другому пути, измените `/var/www/stream_project` в файлах
`deploy/nginx.conf` и `deploy/stream-worker.service`.

## PHP-FPM и Nginx

```bash
sudo cp deploy/php-fpm.ini /etc/php/8.3/fpm/conf.d/99-stream-project.ini
sudo cp deploy/nginx.conf /etc/nginx/sites-available/stream-project
sudo ln -sfn /etc/nginx/sites-available/stream-project /etc/nginx/sites-enabled/stream-project
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl restart php8.3-fpm nginx
```

Откройте `http://IP_СЕРВЕРА/admin`. Корень Nginx направлен только на `public/`;
исходные MP4 из `storage/uploads/` недоступны через интернет.

Лимиты согласованы на обоих уровнях:

- приложение принимает MP4 до 5 ГБ;
- PHP-FPM: `upload_max_filesize=5G`, `post_max_size=5200M`;
- Nginx: `client_max_body_size 5200M`.

## Автоматическая конвертация

Установите постоянный systemd-воркер:

```bash
sudo cp deploy/stream-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now stream-worker
sudo systemctl status stream-worker
```

Логи конвертации:

```bash
sudo journalctl -u stream-worker -f
```

Воркер запускается при загрузке ОС, следит за очередью постоянно и не требует ручного
`php bin/worker.php`. Файловая блокировка не позволяет одновременно запустить второй экземпляр.

## Проверка и управление

```bash
sudo nginx -t
sudo systemctl status nginx php8.3-fpm stream-worker
ffmpeg -version
```

После изменения настроек:

```bash
sudo systemctl restart php8.3-fpm nginx stream-worker
```

Для HTTPS установите Certbot или подключите свой сертификат, затем замените
`server_name _` в Nginx на домен.
