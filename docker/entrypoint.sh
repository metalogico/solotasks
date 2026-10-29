#!/bin/sh
set -eu

: "${APP_KEY:?Set APP_KEY in .env}"
: "${SOLOTASKS_USERNAME:?Set SOLOTASKS_USERNAME in .env}"
: "${SOLOTASKS_PASSWORD_HASH:?Set SOLOTASKS_PASSWORD_HASH in .env}"

mkdir -p \
    /var/lib/sh-solotasks \
    /backups \
    storage/app/private \
    storage/framework/cache/data \
    storage/framework/cache/locks \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

if [ ! -f "$DB_DATABASE" ]; then
    touch "$DB_DATABASE"
fi

chown -R www-data:www-data /var/lib/sh-solotasks /backups storage bootstrap/cache
chmod 700 /var/lib/sh-solotasks /backups
chmod 600 "$DB_DATABASE"

php artisan migrate --force --no-interaction
php artisan view:cache --no-interaction
exec "$@"
