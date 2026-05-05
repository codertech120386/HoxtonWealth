FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
        bash \
        git \
        icu-dev \
        libpq-dev \
        libzip-dev \
        linux-headers \
        oniguruma-dev \
        postgresql-dev \
    && docker-php-ext-install \
        bcmath \
        intl \
        pcntl \
        pdo_pgsql \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

EXPOSE 9000
CMD ["php-fpm"]
