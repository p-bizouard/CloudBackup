# Dev target
FROM php:8.0-fpm AS dev

WORKDIR /app

ENV APP_ENV=dev

RUN apt update \
    && apt install -y wget gnupg \
    && wget --quiet -O - https://www.postgresql.org/media/keys/ACCC4CF8.asc | apt-key add - \
    && echo 'deb http://apt.postgresql.org/pub/repos/apt/ bionic-pgdg main' >> /etc/apt/sources.list \
    && apt update \
    && apt install -y \
        postgresql-client-11 \
        postgresql-client-12 \
        postgresql-client-13 \
        libcurl4-gnutls-dev zlib1g-dev libicu-dev g++ libxml2-dev libpq-dev libonig-dev libzip-dev libldb-dev libpng-dev \
        software-properties-common \
        git unzip procps \
        locales \
        sshfs sshpass \
        postgresql-client \
        mariadb-client \
    && apt-get autoremove && apt-get autoclean \
    && rm -rf /var/lib/apt/lists/*

RUN echo 'fr_FR.UTF-8 UTF-8' >> /etc/locale.gen && \
    locale-gen

RUN pecl install redis xdebug && \
    docker-php-ext-enable redis && \
    docker-php-ext-install intl mbstring pdo pdo_pgsql zip bcmath sockets gd opcache && \
    docker-php-ext-enable xdebug

RUN apt update && apt install -y python3-pip restic graphviz \
    && apt-get autoremove && apt-get autoclean \
    && rm -rf /var/lib/apt/lists/*
    
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

COPY --from=symfonycorp/cli /symfony /usr/local/bin/symfony

CMD  ["php-fpm"]

RUN echo "user:user:1000:1000:Dev user:/:/sbin/nologin" >> /etc/passwd
RUN mkdir /.ssh && chown 1000:1000 /.ssh

# Test image
FROM dev AS test

WORKDIR /app

## Copy project files to workdir
#COPY assets assets
COPY bin bin
COPY config config
COPY fixtures fixtures
COPY public public
COPY src src
COPY templates templates
COPY tests tests
COPY translations translations
COPY migrations migrations
COPY composer.json \
    composer.lock \
    package.json \
    package-lock.json \
    ./
# webpack.config.js \
# postcss.config.js \

## Install application dependencies
RUN APP_ENV=prod composer install \
    --no-dev \
    --no-interaction \
    --optimize-autoloader \
    --no-scripts && \
    chmod +x bin/console

#  Hack to copy files from our nginx custom image

FROM nginx as nginx-build

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
RUN docker-php-ext-install opcache
COPY docker/php/conf/opcache.ini "$PHP_INI_DIR/conf.d/opcache.ini"

# php-fpm is run with www-data
RUN mkdir var && chown -R www-data:www-data var/
RUN mkdir /var/www/.cache/ && chown -R www-data:www-data /var/www/.cache/

USER www-data


#COPY --from=nginx-build /app/public/build/entrypoints.json /app/public/build/entrypoints.json
#COPY --from=nginx-build /app/public/build/manifest.json /app/public/build/manifest.json
