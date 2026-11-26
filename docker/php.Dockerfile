FROM composer:2.11.3 AS composer

FROM php:8.3.37-fpm-trixie
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libpng-dev libjpeg62-turbo-dev libwebp-dev libavif-dev libicu-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-avif \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd intl
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY uploads.ini /usr/local/etc/php/conf.d/zz-lg-uploads.ini
WORKDIR /app
