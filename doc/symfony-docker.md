# Symfony with Docker cheatsheet

## Fix lint errors (this is automaticaly executed on commit, and should be executed in your IDE on save):

```
docker-compose exec php vendor/bin/php-cs-fixer fix
```

## Add a composer dependency

```
docker-compose exec php composer require YOUR_PACKAGE
```

## Update composer dependencies (if someone updated composer.json):

```
docker-compose exec php composer install YOUR_PACKAGE
```

## Add an asset dependency

```
docker-compose exec node_assets npm install YOUR_PACKAGE
```

## Update asset dependencies (if someone updated packages.json):

```
docker-compose exec php composer install
```

## Add properties in your entities :

1. Update your entity :

```
docker-compose run --rm php php bin/console make:entity
```

2. Create a migration file :

```
docker-compose run --rm php php bin/console make:migration
```

3. Execute the migration

```
docker-compose run --rm php php bin/console doctrine:migrations:migrate
```
