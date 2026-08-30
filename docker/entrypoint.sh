#!/bin/sh
set -e

# A missing key means every session and every encrypted value breaks, so generate
# one rather than starting a broken container.
if [ -z "${APP_KEY:-}" ] && [ ! -f /var/www/html/.env ]; then
    cp /var/www/html/.env.example /var/www/html/.env
    php artisan key:generate --force
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    DB_PATH="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    [ -f "$DB_PATH" ] || touch "$DB_PATH"
    # SQLite writes its journal next to the file, so the directory has to be
    # writable too, not just the database itself.
    chown -R www-data:www-data "$(dirname "$DB_PATH")"
fi

# Tell the app that the host owns the image. With this file present the Updates
# screen says the install is managed from outside and points at
# `docker compose pull && docker compose up -d` instead of replacing its own
# files inside a container that would lose them on the next restart.
OTA_DIR=/var/www/html/storage/app/ota
mkdir -p "$OTA_DIR"
printf '{"host":"docker","version":"%s","update":"docker compose pull && docker compose up -d"}\n' \
    "${PHAROS_VERSION:-unknown}" > "$OTA_DIR/update-status.json"
chown -R www-data:www-data "$OTA_DIR"

php artisan migrate --force --no-interaction

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
