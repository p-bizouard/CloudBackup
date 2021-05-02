# CloudBackup

## Description

CloudBackup is a backup software for cloud data. It can backup:

-   Openstack servers
-   MySQL databases (direct connection or with ssh gateway)
-   Custom remote command by ssh
-   Remote directory mounted with sshfs
-   Remote directory backuped by restic if restic is locally available

CloudBackup can store data with Restic

![Workflow](./doc/graph.svg)

## Todo

-   [x] Source - Remote directory
-   [ ] Email reports
-   [ ] Source - PostgreSQL
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

### Useful commands - Symfony with docker cheatsheet

See the [Symfony with docker cheatsheet](doc/symfony-docker.md)

### To update graph in README.md

```
docker-compose run php bash -c "bin/console workflow:dump backup | dot -Tsvg -o doc/graph.svg"
```
