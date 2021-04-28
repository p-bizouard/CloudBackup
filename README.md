# CloudBackup

## Description

CloudBackup is a backup software for cloud data. It can backup:

-   Openstack servers
-   MySQL databases with ssh gateway

CloudBackup can store data with Restic

![Workflow](./doc/graph.svg)

## Todo

-   [ ] Source - Remote directory
-   [ ] Source - PostgreSQL
-   [ ] Email reports
-   [ ] Source - Openstack volume
-   [ ] Destination - Rsync or scp
-   [ ] Destination - FTP
-   [ ] Microsoft Teams or Slack notification

## Requirements

-   Git
-   Docker / docker-compose

## Local installation - execute once

-   Install dependencies, configure environments, run containers:

```
cp -f .env .env.local
```

-   Launch php + database + assets

```
./start-dev.sh
```

> If errors pop up because you already have containers using required ports, you can stop all running containers with `docker stop $(docker ps -aq)`

-   Create database and load fixtures:

```
docker-compose exec php bin/console doctrine:database:create
docker-compose exec php bin/console doctrine:migrations:migrate
docker-compose exec php bin/console hautelook:fixtures:load --env=dev
```

## Development environment

### Launch the development environment

```
./start-dev.sh
```

### Access to your containers

-   Mailhog : http://localhost:8025/
-   phpPgAdmin : http://localhost:9080/
-   Webapp : http://localhost/

## Useful commands - execute if needed

### To fix lint errors (this is automaticaly executed on commit, and should be executed in your IDE on save):

```
docker-compose exec php vendor/bin/php-cs-fixer fix
```

### To add a composer dependency

```
docker-compose exec php composer require YOUR_PACKAGE
```

### To update composer dependencies (if someone updated composer.json):

```
docker-compose exec php composer install YOUR_PACKAGE
```

### To add an asset dependency

```
docker-compose exec node_assets npm install YOUR_PACKAGE
```

### To update asset dependencies (if someone updated packages.json):

```
docker-compose exec php composer install
```

### To add properties in your entities :

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
