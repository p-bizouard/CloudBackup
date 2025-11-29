FROM node:24-alpine AS assets-dev
ARG NODE_ENV=dev
ENV NODE_ENV=dev

WORKDIR /app
ENV PATH /app/node_modules/.bin:$PATH

ENTRYPOINT  ["/bin/sh", "-c"]

# Install on launch, build to produce entrypoints and manifest.json, then watch for changes
CMD ["npm install && npm run build && npm run watch"]

FROM dunglas/frankenphp:1.10 AS dev

ENV DOCKER_USER=1000

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN cp $PHP_INI_DIR/php.ini-development $PHP_INI_DIR/php.ini

# Override php memory limit in php.ini to 512M
RUN echo "memory_limit = 512M" >> $PHP_INI_DIR/conf.d/memory-limit.ini

RUN apt update \
    && apt install -y wget gnupg lsb-release \
    && wget --quiet -O - https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor -o /usr/share/keyrings/postgresql.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/postgresql.gpg] http://apt.postgresql.org/pub/repos/apt/ $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt update \
    && apt install -y \
        libcurl4-gnutls-dev zlib1g-dev libicu-dev g++ libxml2-dev libpq-dev libonig-dev libzip-dev libldb-dev libpng-dev \
        git unzip procps \
        locales \
        sshfs sshpass \
        postgresql-client \
        mariadb-client \
        graphviz \
        bzip2 \
        python3-keystoneclient \
        python3-swiftclient \
        python3-openstackclient

ARG KUBE_VERSION=1.33
RUN curl -fsSL https://pkgs.k8s.io/core:/stable:/v${KUBE_VERSION}/deb/Release.key | gpg --dearmor -o /etc/apt/kubernetes-apt-keyring.gpg \
    && chmod 644 /etc/apt/kubernetes-apt-keyring.gpg \
    && echo 'deb [signed-by=/etc/apt/kubernetes-apt-keyring.gpg] https://pkgs.k8s.io/core:/stable:/v'${KUBE_VERSION}'/deb/ /' | tee /etc/apt/sources.list.d/kubernetes.list \
    && chmod 644 /etc/apt/sources.list.d/kubernetes.list \
    && apt update \
    && apt install -y kubectl

ARG RCLONE_VERSION=1.72.0
RUN curl -fsSL "https://github.com/rclone/rclone/releases/download/v${RCLONE_VERSION}/rclone-v${RCLONE_VERSION}-linux-amd64.zip" -o rclone.zip \
    && unzip rclone.zip \
    && mv "rclone-v${RCLONE_VERSION}-linux-amd64/rclone" /usr/local/bin/ \
    && chmod +x /usr/local/bin/rclone \
    && rm -rf rclone* \
    && apt-get autoremove && apt-get autoclean \
    && rm -rf /var/lib/apt/lists/*

ARG RESTIC_VERSION=0.18.1
RUN wget --quiet "https://github.com/restic/restic/releases/download/v${RESTIC_VERSION}/restic_${RESTIC_VERSION}_linux_amd64.bz2" \
    && bzip2 -d "restic_${RESTIC_VERSION}_linux_amd64.bz2" \
    && mv "restic_${RESTIC_VERSION}_linux_amd64" /usr/local/bin/restic \
    && chmod +x /usr/local/bin/restic

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

COPY --link --chmod=755 docker/frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
ENTRYPOINT ["docker-entrypoint"]

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

FROM node:24-alpine AS assets-build

WORKDIR /app

COPY package*.json \
    ./

RUN --mount=type=cache,target=/root/.npm \
    npm ci

COPY assets assets
COPY public public
COPY webpack.config.js \
    ./

RUN npm run build

FROM dev AS prod

RUN cp $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini

COPY composer.json \
    composer.lock \
    ./

ENV APP_ENV=prod

RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
    --no-dev \
    --no-interaction \
    --optimize-autoloader \
    --no-scripts

# Copy project files to workdir
COPY bin bin
COPY config config
COPY public public
COPY src src
COPY templates templates
COPY translations translations
COPY migrations migrations

COPY --from=assets-build /app/public/build /app/public/build

# Enable worker mode
ENV FRANKENPHP_CONFIG="worker ./public/index.php"
ENV APP_RUNTIME=Runtime\\FrankenPhpSymfony\\Runtime

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]
