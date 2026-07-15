# Laravel queue worker — same baked image as `app`, but runs the queue instead of php-fpm.
# Portless (no ports/expose): a background service in the group.
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
        bash git icu-dev libpq-dev libzip-dev linux-headers oniguruma-dev postgresql-dev \
    && docker-php-ext-install bcmath intl pcntl pdo_pgsql zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

CMD ["php", "artisan", "queue:work", "--tries=3", "--backoff=5", "--sleep=1"]
