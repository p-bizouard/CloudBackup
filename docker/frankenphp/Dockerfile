FROM node:24-alpine AS assets-dev
ARG NODE_ENV=dev
ENV NODE_ENV=dev

WORKDIR /app
ENV PATH /app/node_modules/.bin:$PATH

ENTRYPOINT  ["/bin/sh", "-c"]

# Install on launch, build to produce entrypoints and manifest.json, then watch for changes
CMD ["npm install && npm run build && npm run watch"]

# Node build stage pinned to native build platform — avoids QEMU on cross-arch builds
FROM --platform=$BUILDPLATFORM node:24-alpine AS assets-build

WORKDIR /app

COPY package*.json ./

RUN --mount=type=cache,target=/root/.npm \
    npm ci

COPY assets assets
COPY public public
COPY webpack.config.js ./

RUN npm run build

#
# base — runtime essentials shared by dev + prod (no dev-only tooling)
#
FROM dunglas/frankenphp:1.12.2 AS base

ARG TARGETARCH

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Override php memory limit
RUN echo "memory_limit = 512M" >> $PHP_INI_DIR/conf.d/memory-limit.ini

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    rm -f /etc/apt/apt.conf.d/docker-clean \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        wget gnupg lsb-release ca-certificates curl unzip bzip2 \
    && wget --quiet -O - https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        | gpg --dearmor -o /etc/apt/trusted.gpg.d/postgresql.gpg \
    && echo "deb [signed-by=/etc/apt/trusted.gpg.d/postgresql.gpg] http://apt.postgresql.org/pub/repos/apt/ $(lsb_release -cs)-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-gnutls-dev zlib1g-dev libicu-dev g++ libxml2-dev libpq-dev libonig-dev libzip-dev libldb-dev libpng-dev \
        git \
        locales \
        sshfs sshpass \
        postgresql-client-13 \
        postgresql-client-14 \
        postgresql-client-15 \
        postgresql-client-16 \
        postgresql-client-17 \
        postgresql-client-18 \
        mariadb-client \
        python3-keystoneclient \
        python3-swiftclient \
        python3-openstackclient

# kubectl
ARG KUBE_VERSION=1.36
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    curl -fsSL https://pkgs.k8s.io/core:/stable:/v${KUBE_VERSION}/deb/Release.key | gpg --dearmor -o /etc/apt/kubernetes-apt-keyring.gpg \
    && chmod 644 /etc/apt/kubernetes-apt-keyring.gpg \
    && echo 'deb [signed-by=/etc/apt/kubernetes-apt-keyring.gpg] https://pkgs.k8s.io/core:/stable:/v'${KUBE_VERSION}'/deb/ /' | tee /etc/apt/sources.list.d/kubernetes.list \
    && chmod 644 /etc/apt/sources.list.d/kubernetes.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends kubectl

# rclone — arch-aware
ARG RCLONE_VERSION=1.74.1
RUN curl -fsSL "https://github.com/rclone/rclone/releases/download/v${RCLONE_VERSION}/rclone-v${RCLONE_VERSION}-linux-${TARGETARCH}.zip" -o /tmp/rclone.zip \
    && unzip -q /tmp/rclone.zip -d /tmp \
    && mv "/tmp/rclone-v${RCLONE_VERSION}-linux-${TARGETARCH}/rclone" /usr/local/bin/ \
    && chmod +x /usr/local/bin/rclone \
    && rm -rf /tmp/rclone*

# restic — arch-aware (uses amd64/arm64 naming)
ARG RESTIC_VERSION=0.18.1
RUN wget --quiet "https://github.com/restic/restic/releases/download/v${RESTIC_VERSION}/restic_${RESTIC_VERSION}_linux_${TARGETARCH}.bz2" -O /tmp/restic.bz2 \
    && bzip2 -d /tmp/restic.bz2 \
    && mv /tmp/restic /usr/local/bin/restic \
    && chmod +x /usr/local/bin/restic

# kopia — maps amd64→x64, arm64→arm64
ARG KOPIA_VERSION=0.22.3
RUN case "${TARGETARCH}" in \
        amd64) KOPIA_ARCH=x64 ;; \
        arm64) KOPIA_ARCH=arm64 ;; \
        *) echo "unsupported arch: ${TARGETARCH}" && exit 1 ;; \
    esac \
    && curl -fsSL "https://github.com/kopia/kopia/releases/download/v${KOPIA_VERSION}/kopia-${KOPIA_VERSION}-linux-${KOPIA_ARCH}.tar.gz" -o /tmp/kopia.tar.gz \
    && tar -xzf /tmp/kopia.tar.gz -C /tmp \
    && mv "/tmp/kopia-${KOPIA_VERSION}-linux-${KOPIA_ARCH}/kopia" /usr/local/bin/kopia \
    && chmod +x /usr/local/bin/kopia \
    && rm -rf /tmp/kopia*

RUN install-php-extensions \
    pdo_pgsql \
    gd \
    intl \
    zip \
    opcache \
    mbstring \
    bcmath \
    sockets \
    redis

RUN echo "user:user:${USER:-1000}:${USER:-1000}:Dev user:/:/sbin/nologin" >> /etc/passwd \
    && mkdir /.ssh /.cache \
    && chown ${USER:-1000}:${USER:-1000} /.ssh /.cache \
    && chown -R ${USER}:${USER} /data/caddy /config/caddy

# Caddyfile.d is not used, so we remove the import line to avoid warning messages
RUN sed -e '/import Caddyfile\.d/d' -i /etc/frankenphp/Caddyfile

COPY --chmod=755 docker/frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
ENTRYPOINT ["docker-entrypoint"]

#
# dev — adds debug/dev tools on top of base
#
FROM base AS dev

ENV DOCKER_USER=1000

RUN cp $PHP_INI_DIR/php.ini-development $PHP_INI_DIR/php.ini

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update \
    && apt-get install -y --no-install-recommends \
        procps \
        graphviz

# pcov: code-coverage driver for phpunit (low overhead, dev only).
# Disabled by default; enable via `php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text`.
RUN install-php-extensions pcov \
    && { \
        echo "pcov.enabled=0"; \
        echo "pcov.directory=/app/src"; \
        echo "pcov.exclude=\"~vendor~\""; \
    } > $PHP_INI_DIR/conf.d/pcov.ini

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

#
# prod — slim runtime, vendor + assets baked in
#
FROM base AS prod

RUN cp $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini

ENV APP_ENV=prod

COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
    --no-dev \
    --no-interaction \
    --optimize-autoloader \
    --no-scripts \
    --no-autoloader

# Copy project files to workdir
COPY bin bin
COPY config config
COPY public public
COPY src src
COPY templates templates
COPY translations translations
COPY migrations migrations

COPY --from=assets-build /app/public/build /app/public/build

# Generate authoritative classmap now that src is present
RUN composer dump-autoload --no-dev --classmap-authoritative

# Enable worker mode
ENV FRANKENPHP_CONFIG="worker ./public/index.php"
ENV APP_RUNTIME=Runtime\\FrankenPhpSymfony\\Runtime

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]
