# Laravel PHP-FPM (FastCGI on :9000) with the app code BAKED IN (no volume mounts) — the nginx
# `web` service proxies HTTP to it. Deployable standalone (Fleet builds this via docker-compose).
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
        bash git icu-dev libpq-dev libzip-dev linux-headers oniguruma-dev postgresql-dev \
    && docker-php-ext-install bcmath intl pcntl pdo_pgsql zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

# On boot: run migrations against the EXTERNAL database, cache config, then serve FastCGI.
# (migrate is idempotent; a wrong DB_* here surfaces in the logs and the request errors.)
CMD ["sh", "-c", "php artisan migrate --force --no-interaction || echo 'migrate failed — check DB_* env'; php artisan config:cache || true; exec php-fpm"]
