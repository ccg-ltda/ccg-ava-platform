# syntax=docker/dockerfile:1.7
#
# Single image for every role (app, worker, scheduler, reverb). Roles only differ in the
# command they run. Targets: `development` (code is bind-mounted) and `production`.

# composer.lock pins symfony/* 8.x, which requires PHP >= 8.4.1 (composer.json only says ^8.3).
ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

FROM composer:2 AS composer_bin

# ---------------------------------------------------------------------------
# base: PHP-FPM + Nginx + extensions shared by every target
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base

RUN apk add --no-cache nginx supervisor curl icu-libs libzip postgresql-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl bcmath intl zip opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear /var/cache/apk/*

COPY --from=composer_bin /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_FPM_MAX_CHILDREN=20 \
    PHP_MEMORY_LIMIT=256M \
    PHP_UPLOAD_MAX=50M

WORKDIR /var/www/html
ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
EXPOSE 80

# ---------------------------------------------------------------------------
# vendor: production PHP dependencies
# ---------------------------------------------------------------------------
FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ---------------------------------------------------------------------------
# frontend: Vite build (React 19 + Tailwind 4)
# ---------------------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js postcss.config.js tailwind.config.js jsconfig.json ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---------------------------------------------------------------------------
# development: code and vendor come from bind mounts / volumes (see docker-compose.yml)
# ---------------------------------------------------------------------------
FROM base AS development
ENV APP_ENV=local PHP_OPCACHE_VALIDATE_TIMESTAMPS=1

# ---------------------------------------------------------------------------
# production: self-contained, stateless image
# ---------------------------------------------------------------------------
FROM base AS production
ENV APP_ENV=production APP_DEBUG=false

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache \
    && rm -f /usr/bin/composer
