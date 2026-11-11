#!/bin/sh
set -e

if [ "$(id -u)" = "0" ]; then
    mkdir -p /var/www/html/storage/app/public \
             /var/www/html/storage/app/private/scheduler \
             /var/www/html/storage/app/private/backups \
             /var/www/html/storage/app/private/imports \
             /var/www/html/storage/framework/cache/data \
             /var/www/html/storage/framework/sessions \
             /var/www/html/storage/framework/views \
             /var/www/html/storage/logs \
             /var/www/html/bootstrap/cache
    chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
    chmod -R ug+rwx /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
fi

exec "$@"
