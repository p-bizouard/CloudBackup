#!/bin/bash

set -euo pipefail

if [[ ! -f /app/vendor/autoload.php ]]; then
  echo "Installing Composer dev dependencies..."
  composer install --no-interaction --optimize-autoloader
fi

# Do Symfony migrations
echo "Running database migrations..."
php bin/console doctrine:migrations:migrate -n --allow-no-migration

# Dump easyadmin bundle assets
echo "Installing assets..."
php bin/console assets:install --symlink --relative

# Clear existing dumped env, and dump environment if env APP_ENV is set to prod
echo "Cleaning environment..."
rm -f .env.local.php
if [[ "${APP_ENV:-dev}" == "prod" ]]; then
  echo "Dumping production environment..."
  composer dump-env prod
fi


echo "Starting application..."
exec docker-php-entrypoint "$@"