ARG NGINX_DESTINATION=cloudbackup_nginx

# Dev target
FROM php:8.4-fpm-bullseye AS dev

WORKDIR /app

ENV APP_ENV=dev

RUN apt update \
    && apt install -y wget gnupg lsb-release software-properties-common \
    && wget --quiet -O - https://www.postgresql.org/media/keys/ACCC4CF8.asc | apt-key add - \
    && wget --quiet -O - https://www.mongodb.org/static/pgp/server-5.0.asc | apt-key add - \
    && add-apt-repository "deb https://repo.mongodb.org/apt/debian buster/mongodb-org/5.0 main" \
    && add-apt-repository "deb http://apt.postgresql.org/pub/repos/apt/ $(lsb_release -cs)-pgdg main" \
    && curl -fsSL https://pkgs.k8s.io/core:/stable:/v1.33/deb/Release.key | gpg --dearmor -o /etc/apt/kubernetes-apt-keyring.gpg \
    && chmod 644 /etc/apt/kubernetes-apt-keyring.gpg \
    && echo 'deb [signed-by=/etc/apt/kubernetes-apt-keyring.gpg] https://pkgs.k8s.io/core:/stable:/v1.33/deb/ /' | tee /etc/apt/sources.list.d/kubernetes.list \
    && chmod 644 /etc/apt/sources.list.d/kubernetes.list \
    && apt update \
    && apt install -y \
        libcurl4-gnutls-dev zlib1g-dev libicu-dev g++ libxml2-dev libpq-dev libonig-dev libzip-dev libldb-dev libpng-dev \
        git unzip procps \
        locales \
        sshfs sshpass \
        postgresql-client \
        mariadb-client \
        postgresql-client-11 \
        postgresql-client-12 \
        postgresql-client-13 \
        postgresql-client-14 \
        postgresql-client-15 \
        postgresql-client-16 \
        postgresql-client-17 \
        python3-pip graphviz \
        kubectl \
        bzip2
        # mongodb-org-tools \
RUN curl https://rclone.org/install.sh | bash \
    && apt-get autoremove && apt-get autoclean \
    && rm -rf /var/lib/apt/lists/*

RUN wget --quiet https://github.com/restic/restic/releases/download/v0.18.0/restic_0.18.0_linux_amd64.bz2 && \
    bzip2 -d restic_0.18.0_linux_amd64.bz2 && \
    mv restic_0.18.0_linux_amd64 /usr/local/bin/restic && \
    chmod +x /usr/local/bin/restic

RUN echo 'fr_FR.UTF-8 UTF-8' >> /etc/locale.gen && \
    locale-gen

RUN pecl install redis xdebug && \
    docker-php-ext-enable redis && \
    docker-php-ext-install intl mbstring pdo pdo_pgsql zip bcmath sockets gd opcache && \
    docker-php-ext-enable xdebug

COPY requirements.txt requirements.txt
RUN pip3 install --upgrade pip
RUN pip3 install -r requirements.txt

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN echo "date.timezone = Europe/Paris" >> /usr/local/etc/php/conf.d/timezone.ini && \
    echo 'memory_limit = 512M' >> /usr/local/etc/php/conf.d/docker-php-memlimit.ini && \
    echo 'expose_php = off' >> /usr/local/etc/php/conf.d/docker-php-expose.ini

RUN echo "access.log = /dev/null" >> /usr/local/etc/php-fpm.d/www.conf

ADD docker/php/symfony-entry-point.sh /
ENTRYPOINT  ["/bin/bash", "/symfony-entry-point.sh"]

CMD  ["php-fpm"]

RUN echo "user:user:${DOCKER_USER:-1000}:${DOCKER_USER:-1000}:Dev user:/:/sbin/nologin" >> /etc/passwd
RUN mkdir /.ssh && chown ${DOCKER_USER:-1000}:${DOCKER_USER:-1000} /.ssh
RUN mkdir /.cache && chown ${DOCKER_USER:-1000}:${DOCKER_USER:-1000} /.cache

# Test image
FROM dev AS test

WORKDIR /app

## Copy composer configuration files
COPY composer.json \
    composer.lock \
    ./

# Install composer dependencies
# COMPOSER_ALLOW_SUPERUSER=1 is required to run plugins, and write autoload_runtime.php
RUN APP_ENV=prod COMPOSER_ALLOW_SUPERUSER=1 composer install \
    --no-dev \
    --no-interaction \
    --optimize-autoloader \
    --no-scripts

# Copy project files to workdir
COPY bin bin
COPY config config
COPY fixtures fixtures
COPY public public
COPY src src
COPY templates templates
COPY tests tests
COPY translations translations
COPY migrations migrations

#  Hack to copy files from our nginx custom image
FROM $NGINX_DESTINATION AS nginx-build

# Prod image
FROM test AS prod

WORKDIR /app

ENV APP_ENV=prod

# Disable xdebug on production
RUN rm -f /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# opcache configuration
ENV PHP_OPCACHE_ENABLE="1"
ENV PHP_OPCACHE_MEMORY_CONSUMPTION="128"
ENV PHP_OPCACHE_MAX_ACCELERATED_FILES="12000"
ENV PHP_OPCACHE_REVALIDATE_FREQUENCY="0"
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS="0"
# Install opcache lib and add the configuration bellow
COPY docker/php/conf/opcache.ini "$PHP_INI_DIR/conf.d/opcache.ini"

# php-fpm is run with www-data
RUN mkdir var && chown -R www-data:www-data var/
RUN mkdir /var/www/.cache/ && chown -R www-data:www-data /var/www/.cache/
RUN mkdir /var/www/.ssh/ && chown -R www-data:www-data /var/www/.ssh/
RUN mkdir -p public/bundles && chown www-data:www-data public/bundles
RUN touch .env.local.php && chown www-data:www-data .env.local.php

# Required for fusermount
RUN if [ ! -L /etc/mtab ]; then ln -s /proc/mounts /etc/mtab; fi

USER www-data

COPY --from=nginx-build /app/public/build/entrypoints.json /app/public/build/entrypoints.json
COPY --from=nginx-build /app/public/build/manifest.json /app/public/build/manifest.json