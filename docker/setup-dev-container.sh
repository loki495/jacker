#!/usr/bin/env bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends git unzip curl libzip-dev libsqlite3-dev libicu-dev libonig-dev
# pdo_sqlite is enabled in the official image; build it explicitly for portability.
docker-php-ext-install -j"$(nproc)" pdo_sqlite zip intl mbstring bcmath
# pcov is loaded but off by default; coverage runs switch it on with -d pcov.enabled=1.
pecl install pcov
docker-php-ext-enable pcov
rm -rf /var/lib/apt/lists/*
usermod -u "${APP_UID:-1000}" www-data
groupmod -g "${APP_GID:-1000}" www-data
sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf
printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/jobs.conf
a2enmod rewrite headers
a2enconf jobs
printf 'memory_limit=512M\nupload_max_filesize=10M\npost_max_size=12M\nexpose_php=Off\ndisplay_errors=Off\nlog_errors=On\n' > /usr/local/etc/php/conf.d/jobs.ini
printf 'pcov.enabled=0\npcov.directory=/var/www/html/app\n' > /usr/local/etc/php/conf.d/zz-pcov.ini
