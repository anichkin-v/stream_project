# KidsTub — детская видеотека

Видеосервис на чистом PHP: администратор загружает MP4, системный воркер конвертирует его
на CPU в HLS, после чего видео появляется в детском каталоге.

## Возможности

- адаптивный HLS: 480p, 720p и 1080p без увеличения разрешения исходника;
- собственный детский плеер: качество, громкость, перемотка и fullscreen;
- превью кадров при наведении на таймлайн;
- сериалы, сезоны и автоматический переход к следующей серии;
- отдельные вкладки настроек плеера и сайта в админ-панели;
- хранение исходников и HLS локально, на смонтированном NAS или в Amazon S3;
- фоновые CPU-конвертации с живым прогрессом.

Существующие готовые видео можно перевести в новый формат кнопкой «Перекодировать».
Новые загрузки сразу получают adaptive master playlist.

Проект разворачивается в `/home/web_deploy/web/public/stream`; writable-каталоги принадлежат
`www-data`. Document root Nginx указывает на вложенный каталог `public/`, то есть на
`/home/web_deploy/web/public/stream/public`.

## Быстрый старт (один скрипт)

Автоматическая установка и запуск на чистом сервере. Из корня проекта:

```bash
sudo bash install.sh
```

Скрипт ставит пакеты, синхронизирует проект в `/home/web_deploy/web/public/stream`,
готовит права, инициализирует БД, создаёт администратора, устанавливает конфиги
Nginx/PHP-FPM и запускает systemd-воркер. Значения по умолчанию можно переопределить
переменными окружения:

```bash
sudo PROJECT_DIR=/home/web_deploy/web/public/stream DEPLOY_USER=www-data \
  PHP_VERSION=8.4 ADMIN_LOGIN=admin bash install.sh
```

Ниже — те же шаги вручную.

## Установка на Ubuntu 24.04

Проект работает от пользователя `www-data` и использует PHP 8.4:

```bash
sudo apt update
sudo apt install nginx php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-mbstring \
  php8.4-curl ffmpeg
```

Скопируйте проект в `/home/web_deploy/web/public/stream`, затем подготовьте каталоги и базу.
Document root — вложенный каталог `public/`, то есть `/home/web_deploy/web/public/stream/public`.
Каталог `vendor/` для локального и сетевого хранилища **не нужен** — AWS SDK
подключается только при выборе S3 (`INSTALL_S3=1` или ручной `composer install`):

```bash
cd /home/web_deploy/web/public/stream
mkdir -p storage/uploads public/media
sudo chown -R www-data:www-data /home/web_deploy/web/public/stream
sudo chmod -R 775 storage public/media
# www-data должен иметь право прохода к каталогу проекта в /home:
sudo chmod o+x /home /home/web_deploy /home/web_deploy/web /home/web_deploy/web/public
# только если используете Amazon S3:
# sudo apt install composer
# sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php bin/init-db.php
sudo -u www-data php bin/create-admin.php admin
```

Если путь или пользователь другие, замените `/home/web_deploy/web/public/stream`,
`www-data` и `php8.4` в файлах `deploy/nginx.conf` и `deploy/stream-worker.service`.

## PHP-FPM и Nginx

Используется стандартный пул PHP-FPM (`www-data`) и сокет `/run/php/php8.4-fpm.sock`.

```bash
sudo cp deploy/php-fpm.ini /etc/php/8.4/fpm/conf.d/99-stream-project.ini
sudo cp deploy/nginx.conf /etc/nginx/sites-available/stream-project
sudo ln -sfn /etc/nginx/sites-available/stream-project /etc/nginx/sites-enabled/stream-project
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl restart php8.4-fpm nginx
```

Откройте `http://IP_СЕРВЕРА/admin`. Корень Nginx направлен только на `public/`;
исходные MP4 из `storage/uploads/` недоступны через интернет.

## Хранилище контента

Тип и пути задаются в админке: **Хранилище**. Доступны локальные абсолютные пути,
предварительно смонтированный NFS/SMB (например, `/mnt/kidstub`) и Amazon S3.

Для каталога HLS задайте публичный URL. Если HLS лежит на NAS, добавьте для этого URL
`alias` в Nginx. Для S3 используйте публичный bucket или CloudFront и разрешите CORS
для домена KidsTub (`GET`, `HEAD`). Credentials не хранятся в SQLite: настройте IAM role
либо стандартный AWS-профиль пользователя `www-data` в `/var/www/.aws/credentials`.
Для systemd-воркера также можно создать `/etc/kidstub/storage.env`:

```bash
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_SESSION_TOKEN=...
```

Защитите файл командой `sudo chmod 600 /etc/kidstub/storage.env`. PHP-FPM должен получать
те же credentials через IAM role или AWS-профиль пользователя `www-data`.

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
sudo systemctl status nginx php8.4-fpm stream-worker
ffmpeg -version
```

После изменения настроек:

```bash
sudo systemctl restart php8.4-fpm nginx stream-worker
```

Для HTTPS установите Certbot или подключите свой сертификат, затем замените
`server_name _` в Nginx на домен.

Готовый vhost с доменом и HTTPS — `deploy/stream.example.com.conf`. Замените в нём
`example.com` на свой домен и следуйте комментариям внутри файла.
