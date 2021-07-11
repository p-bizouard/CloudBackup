#!/bin/sh

# If dependencies are missing, install them
# (should happen only in DEV environnement)
if [[ ! -f /app/vendor/autoload.php ]]; then
  composer install --no-interaction --optimize-autoloader
fi

# Do Symfony migrations
php bin/console doctrine:migrations:migrate -n

# Dump easyadmin bundle assets
php bin/console assets:install

# Dump environment
composer dump-env prod

echo $@
exec "$@"

