ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        libxml2-dev \
        libonig-dev \
        libgmp-dev \
        unzip \
        git \
        curl \
        iproute2 \
        iputils-ping \
        openssh-client \
        nano \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        pdo_mysql \
        pdo_pgsql \
        zip \
        opcache \
        gd \
        xml \
        mbstring \
        gmp \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && apt-get update && apt-get install -y --no-install-recommends libpq5 \
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

ARG INSTALL_FFMPEG=""
# Optional: install ffmpeg and yt-dlp for media processing.
# Enabled by setting PHP83_FFMPEG=1 or PHP84_FFMPEG=1 in .env.
RUN if [ -n "${INSTALL_FFMPEG}" ]; then \
    apt-get update && apt-get install -y --no-install-recommends ffmpeg \
    && ARCH=$(dpkg --print-architecture) \
    && if [ "$ARCH" = "amd64" ]; then YT_BIN="yt-dlp_linux"; else YT_BIN="yt-dlp_linux_aarch64"; fi \
    && curl -fsSL "https://github.com/yt-dlp/yt-dlp/releases/latest/download/${YT_BIN}" -o /usr/local/bin/yt-dlp \
    && chmod +x /usr/local/bin/yt-dlp \
    && rm -rf /var/lib/apt/lists/*; \
fi

# Install gbt prompt (https://github.com/jtyr/gbt)
ARG TARGETARCH
RUN curl -fsSL "https://github.com/jtyr/gbt/releases/download/v2.0.0/gbt-2.0.0-linux-${TARGETARCH}.tar.gz" \
    -o /tmp/gbt.tar.gz \
    && tar -xzf /tmp/gbt.tar.gz -C /tmp \
    && mv /tmp/gbt-2.0.0/gbt /usr/local/bin/gbt \
    && rm -rf /tmp/gbt.tar.gz /tmp/gbt-2.0.0

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install Symfony CLI
RUN curl -sS https://get.symfony.com/cli/installer | bash \
    && mv /root/.symfony5/bin/symfony /usr/local/bin/symfony

# Create user/group for host UID so shell doesn't show "I have no name!"
ARG HOST_UID=1000
RUN if ! getent group ${HOST_UID} >/dev/null; then \
        groupadd -g ${HOST_UID} dev; \
    fi \
    && if ! getent passwd ${HOST_UID} >/dev/null; then \
        useradd -u ${HOST_UID} -g ${HOST_UID} -m -s /bin/bash dev; \
    fi

# Create writable .claude directory for Claude Code credential passthrough.
# The actual credentials are mounted at runtime — nothing secret is baked in.
RUN HOME_DIR=$(getent passwd ${HOST_UID} | cut -d: -f6) \
    && mkdir -p "$HOME_DIR/.claude" \
    && chown ${HOST_UID}:${HOST_UID} "$HOME_DIR/.claude" \
    && mkdir -p "$HOME_DIR/.config/composer" \
    && echo '{"config":{"github-protocols":["ssh"],"use-github-api":false}}' > "$HOME_DIR/.config/composer/config.json" \
    && chown -R ${HOST_UID}:${HOST_UID} "$HOME_DIR/.config"

ARG NODE_VERSIONS=""
# Optional: install nvm and Node.js versions.
# Enabled by setting NODE_VERSIONS to a comma-separated list (e.g. "18,20,22").
# Must run BEFORE the git SSH rewrite below, since nvm's installer clones from GitHub via HTTPS.
RUN if [ -n "${NODE_VERSIONS}" ]; then \
    HOME_DIR=$(getent passwd ${HOST_UID} | cut -d: -f6) \
    && export NVM_DIR="$HOME_DIR/.nvm" \
    && mkdir -p "$NVM_DIR" \
    && curl -fsSL https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | PROFILE=/dev/null bash \
    && . "$NVM_DIR/nvm.sh" \
    && IFS=',' ; for ver in ${NODE_VERSIONS}; do \
        ver=$(echo "$ver" | xargs); \
        [ -n "$ver" ] && nvm install "$ver"; \
    done \
    && corepack enable \
    && corepack prepare yarn@stable --activate \
    && chown -R ${HOST_UID}:${HOST_UID} "$NVM_DIR" \
    && echo 'export NVM_DIR="$HOME/.nvm"' >> "$HOME_DIR/.bashrc" \
    && echo '[ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"' >> "$HOME_DIR/.bashrc"; \
fi

# Rewrite HTTPS GitHub URLs to SSH so Composer uses the mounted SSH keys
# for private repositories instead of requiring a personal access token.
RUN git config --system url."git@github.com:".insteadOf "https://github.com/"

# Activate gbt prompt for interactive shells
ENV GBT_CARS='Hostname, Dir, Git, Sign' \
    GBT_CAR_HOSTNAME_FORMAT=' {{ Host }} ' \
    GBT_CAR_HOSTNAME_BG=red \
    GBT_CAR_HOSTNAME_FG=white
RUN echo "PS1='\$(gbt \$?)'" >> /etc/skel/.bashrc \
    && { [ -d /home/dev ] && echo "PS1='\$(gbt \$?)'" >> /home/dev/.bashrc || true; }

ARG WEB_ROOT=/home/wobble/web
RUN mkdir -p "$WEB_ROOT"

RUN chmod 1777 /tmp

WORKDIR $WEB_ROOT
