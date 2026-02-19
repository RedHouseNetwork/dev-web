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
        iproute2 \
        iputils-ping \
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
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && rm -rf /var/lib/apt/lists/*

ARG INSTALL_XDEBUG=""
RUN if [ -n "${INSTALL_XDEBUG}" ]; then \
        pecl install xdebug \
        && docker-php-ext-enable xdebug; \
    fi

ARG SQLCIPHER_VERSION=""
# Optional: compile SQLCipher and rebuild PHP sqlite extensions against it.
# Enabled by setting SQLCIPHER_VERSION to a release tag (e.g. "4.10.0").
RUN if [ -n "${SQLCIPHER_VERSION}" ]; then \
    set -eux && \
    apt-get update && apt-get install -y --no-install-recommends \
        build-essential \
        pkg-config \
        autoconf \
        automake \
        libtool \
        libssl-dev \
        tcl \
    && git clone --branch "v${SQLCIPHER_VERSION}" --single-branch --depth 1 \
        https://github.com/sqlcipher/sqlcipher.git /usr/src/sqlcipher \
    && cd /usr/src/sqlcipher \
    && ./configure \
        --prefix=/usr/local/sqlcipher \
        --with-tempstore=yes \
        --enable-shared \
        --disable-tcl \
        CFLAGS="-DSQLITE_HAS_CODEC -DSQLITE_TEMP_STORE=2 -DSQLITE_MAX_VARIABLE_NUMBER=250000 -DSQLITE_EXTRA_INIT=sqlcipher_extra_init -DSQLITE_EXTRA_SHUTDOWN=sqlcipher_extra_shutdown -fPIC -O2" \
        LDFLAGS="-lcrypto" \
    && make -j"$(nproc)" \
    && make install \
    && echo "/usr/local/sqlcipher/lib" > /etc/ld.so.conf.d/sqlcipher.conf \
    && ldconfig \
    && docker-php-source extract \
    && mv /usr/src/php/ext/sqlite3/config0.m4 /usr/src/php/ext/sqlite3/config.m4 \
    && PKG_CONFIG_PATH="/usr/local/sqlcipher/lib/pkgconfig" \
        docker-php-ext-configure sqlite3 --with-sqlite3=/usr/local/sqlcipher \
    && PKG_CONFIG_PATH="/usr/local/sqlcipher/lib/pkgconfig" \
        docker-php-ext-configure pdo_sqlite --with-pdo-sqlite=/usr/local/sqlcipher \
    && docker-php-ext-install -j"$(nproc)" sqlite3 pdo_sqlite \
    && docker-php-source delete \
    && php -r '$v = (new PDO("sqlite::memory:"))->query("PRAGMA cipher_version")->fetchColumn(); if (!$v) exit(1); echo "SQLCipher $v OK\n";' \
    && rm -rf /usr/src/sqlcipher \
    && apt-get purge -y --auto-remove build-essential pkg-config autoconf automake libtool libssl-dev tcl \
    && rm -rf /var/lib/apt/lists/*; \
fi

ARG CHROME_VERSION="128.0.6613.137"
# Optional: install Chrome + ChromeDriver for Symfony Panther E2E tests.
# Enabled by default; set CHROME_VERSION to empty to disable.
RUN if [ -n "$CHROME_VERSION" ]; then \
    apt-get update && apt-get install -y --no-install-recommends \
        wget \
        fonts-liberation libnss3 libxss1 libasound2 libatk-bridge2.0-0 \
        libgtk-3-0 libdrm2 libgbm1 libxshmfence1 \
    && ARCH=$(dpkg --print-architecture) \
    && if [ "$ARCH" = "amd64" ]; then CHROME_ARCH="linux64"; else CHROME_ARCH="linux-arm64"; fi \
    && wget -q "https://storage.googleapis.com/chrome-for-testing-public/${CHROME_VERSION}/${CHROME_ARCH}/chrome-${CHROME_ARCH}.zip" -O /tmp/chrome.zip \
    && wget -q "https://storage.googleapis.com/chrome-for-testing-public/${CHROME_VERSION}/${CHROME_ARCH}/chromedriver-${CHROME_ARCH}.zip" -O /tmp/chromedriver.zip \
    && unzip /tmp/chrome.zip -d /opt/ \
    && unzip /tmp/chromedriver.zip -d /opt/ \
    && ln -s /opt/chrome-${CHROME_ARCH}/chrome /usr/local/bin/google-chrome \
    && ln -s /opt/chromedriver-${CHROME_ARCH}/chromedriver /usr/local/bin/chromedriver \
    && rm /tmp/chrome.zip /tmp/chromedriver.zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    ; fi

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
