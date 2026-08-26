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

php artisan migrate --force --no-interaction

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
