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
        curl \
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

# Install gbt prompt (https://github.com/jtyr/gbt)
ARG TARGETARCH
RUN curl -fsSL "https://github.com/jtyr/gbt/releases/download/v2.0.0/gbt-2.0.0-linux-${TARGETARCH}.tar.gz" \
    -o /tmp/gbt.tar.gz \
    && tar -xzf /tmp/gbt.tar.gz -C /tmp \
    && mv /tmp/gbt-2.0.0/gbt /usr/local/bin/gbt \
    && rm -rf /tmp/gbt.tar.gz /tmp/gbt-2.0.0

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Create user/group for host UID so shell doesn't show "I have no name!"
ARG HOST_UID=1000
RUN if ! getent group ${HOST_UID} >/dev/null; then \
        groupadd -g ${HOST_UID} dev; \
    fi \
    && if ! getent passwd ${HOST_UID} >/dev/null; then \
        useradd -u ${HOST_UID} -g ${HOST_UID} -m -s /bin/bash dev; \
    fi

# Activate gbt prompt for interactive shells
ENV GBT_CAR__HOSTNAME__FORMAT='{{ .Host }}' \
    GBT_CAR__HOSTNAME__BG=red \
    GBT_CAR__HOSTNAME__FG=white
RUN echo "PS1='\$(gbt \$?)'" >> /etc/skel/.bashrc \
    && { [ -d /home/dev ] && echo "PS1='\$(gbt \$?)'" >> /home/dev/.bashrc || true; }

ARG WEB_ROOT=/home/wobble/web
RUN mkdir -p "$(dirname "$WEB_ROOT")" && ln -s /srv/web "$WEB_ROOT"

RUN chmod 1777 /tmp

WORKDIR /srv/web
