ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libxml2-dev \
        libonig-dev \
        unzip \
        git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        pdo_mysql \
        pdo_pgsql \
        zip \
        opcache \
        gd \
        xml \
        mbstring \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ARG WEB_ROOT=/home/wobble/web
RUN mkdir -p "$(dirname "$WEB_ROOT")" && ln -s /srv/web "$WEB_ROOT"

WORKDIR /srv/web
