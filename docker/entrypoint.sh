#!/bin/sh
# Shared entrypoint for every role. Behaviour is driven by environment variables:
#   COMPOSER_INSTALL=true   run `composer install` first (development, code is bind-mounted)
#   RUN_MIGRATIONS=true     run `php artisan migrate --force` (development only; never in production)
#   OPTIMIZE=true           cache config/routes/views/events (production)
set -e
cd /var/www/html

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

if [ "${COMPOSER_INSTALL:-false}" = "true" ]; then
    composer install --no-interaction --prefer-dist
fi

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

if [ "${OPTIMIZE:-false}" = "true" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
