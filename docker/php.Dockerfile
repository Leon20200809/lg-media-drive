FROM composer:2.11.3 AS composer

FROM php:8.3.37-fpm-trixie
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY uploads.ini /usr/local/etc/php/conf.d/zz-lg-uploads.ini
WORKDIR /app
