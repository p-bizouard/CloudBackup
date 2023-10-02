# CloudBackup

## Description

CloudBackup is a backup software powered by Restic. It can backup:

-   Openstack instances
-   MySQL/PostgreSQL databases (direct connection or with ssh gateway)
-   Custom remote command by ssh (download a single file. Should use tar to backup a directory)
-   Remote directory mounted with sshfs
-   SFTP
-   Everything with Rclone (source and destination must both use rclone)
-   Check and alert on en external restic repository

The dashboard show all backup statuses, and provide the restic commands to download your data.

### Global workflow

![Workflow](./doc/graph.svg)

### Openstack instance

[![](https://mermaid.ink/img/eyJjb2RlIjoiZmxvd2NoYXJ0IExSXG4gICAgY2xhc3NEZWYgcmVkIGZpbGw6I2Y1NSxzdHJva2U6I2Y1NTtcbiAgICBjbGFzc0RlZiBncmVlbiBmaWxsOiM1ZjUsc3Ryb2tlOiM1ZjU7XG5cbiAgICAgc3ViZ3JhcGggY2xlYW51cFN1YiBbQ2xlYW51cF1cbiAgICAgICAgY2xlYW51cFRleHRbUmVtb3ZlIHNuYXBzaG90IGZyb20gT1Mgc3RvcmFnZV1cbiAgICAgICAgY2xlYW51cFRleHQyW1JlbW92ZSBzbmFwc2hvdCBmcm9tIGxvY2FsIHN0b3JhZ2VdXG4gICAgICAgIGNsZWFudXBUZXh0M1tQdXJnZSBvbGQgYmFja3Vwc11cbiAgICAgZW5kXG5cbiAgICAgc3ViZ3JhcGggTnRoIHJ1blxuICAgICAgICBkdW1wMihbRHVtcF0pIC0tPiBjaGVja1NuYXAye1NuYXBzaHRvdCBleGlzdHMgP31cbiAgICAgICAgY2hlY2tTbmFwMiAtLT4gfHllc3wgZG93bmxvYWQoW0Rvd25sb2FkXSlcbiAgICAgICAgY2hlY2tTbmFwMiAtLT4gfG5vfCBleGl0MltFeGl0XTo6OnJlZFxuICAgICAgICBkb3dubG9hZCAtLT4gfHZhbGlkYXRlIGNoZWNrc3Vtc3wgdXBsb2FkKFtVcGxvYWRdKVxuICAgICAgICB1cGxvYWQgLS0-IGNsZWFudXBTdWIoW0NsZWFudXBdKVxuICAgICAgICBjbGVhbnVwU3ViIC0tPiBoZWFsdGhfY2hlY2soW0hlYWx0aCBjaGVja10pXG4gICAgICAgIGhlYWx0aF9jaGVjayAtLT4gfG9uIHN1Y2Nlc3N8IGJhY2t1cGVkKFtCYWNrdXBlZF0pOjo6Z3JlZW5cbiAgICAgICAgaGVhbHRoX2NoZWNrIC0tPiB8b24gd2FybmluZ3N8IGZhaWxlZChbRmFpbGVkXSk6OjpyZWRcbiAgICAgZW5kXG5cbiAgICAgc3ViZ3JhcGggRmlyc3QgcnVuXG4gICAgICAgIGluaXRpYWxpemVkKFtJbml0aWFsaXplZF0pIC0tPiBzdGFydChbU3RhcnRdKVxuICAgICAgICBzdGFydCAtLT4gY2hlY2tTbmFwe1NuYXBzaHRvdCBleGlzdHMgP31cbiAgICAgICAgY2hlY2tTbmFwIC0tPiB8WWVzfCBkb3dubG9hZChbRG93bmxvYWRdKVxuICAgICAgICBjaGVja1NuYXAgLS0-IHxOb3wgZHVtcChbRHVtcF0pIC0tPiBleGl0W0V4aXRdOjo6Z3JlZW5cbiAgICAgZW5kIiwibWVybWFpZCI6e30sInVwZGF0ZUVkaXRvciI6ZmFsc2V9)](https://mermaid-js.github.io/mermaid-live-editor/#/edit/eyJjb2RlIjoiZmxvd2NoYXJ0IExSXG4gICAgY2xhc3NEZWYgcmVkIGZpbGw6I2Y1NSxzdHJva2U6I2Y1NTtcbiAgICBjbGFzc0RlZiBncmVlbiBmaWxsOiM1ZjUsc3Ryb2tlOiM1ZjU7XG5cbiAgICAgc3ViZ3JhcGggY2xlYW51cFN1YiBbQ2xlYW51cF1cbiAgICAgICAgY2xlYW51cFRleHRbUmVtb3ZlIHNuYXBzaG90IGZyb20gT1Mgc3RvcmFnZV1cbiAgICAgICAgY2xlYW51cFRleHQyW1JlbW92ZSBzbmFwc2hvdCBmcm9tIGxvY2FsIHN0b3JhZ2VdXG4gICAgICAgIGNsZWFudXBUZXh0M1tQdXJnZSBvbGQgYmFja3Vwc11cbiAgICAgZW5kXG5cbiAgICAgc3ViZ3JhcGggTnRoIHJ1blxuICAgICAgICBkdW1wMihbRHVtcF0pIC0tPiBjaGVja1NuYXAye1NuYXBzaHRvdCBleGlzdHMgP31cbiAgICAgICAgY2hlY2tTbmFwMiAtLT4gfHllc3wgZG93bmxvYWQoW0Rvd25sb2FkXSlcbiAgICAgICAgY2hlY2tTbmFwMiAtLT4gfG5vfCBleGl0MltFeGl0XTo6OnJlZFxuICAgICAgICBkb3dubG9hZCAtLT4gfHZhbGlkYXRlIGNoZWNrc3Vtc3wgdXBsb2FkKFtVcGxvYWRdKVxuICAgICAgICB1cGxvYWQgLS0-IGNsZWFudXBTdWIoW0NsZWFudXBdKVxuICAgICAgICBjbGVhbnVwU3ViIC0tPiBoZWFsdGhfY2hlY2soW0hlYWx0aCBjaGVja10pXG4gICAgICAgIGhlYWx0aF9jaGVjayAtLT4gfG9uIHN1Y2Nlc3N8IGJhY2t1cGVkKFtCYWNrdXBlZF0pOjo6Z3JlZW5cbiAgICAgICAgaGVhbHRoX2NoZWNrIC0tPiB8b24gd2FybmluZ3N8IGZhaWxlZChbRmFpbGVkXSk6OjpyZWRcbiAgICAgZW5kXG5cbiAgICAgc3ViZ3JhcGggRmlyc3QgcnVuXG4gICAgICAgIGluaXRpYWxpemVkKFtJbml0aWFsaXplZF0pIC0tPiBzdGFydChbU3RhcnRdKVxuICAgICAgICBzdGFydCAtLT4gY2hlY2tTbmFwe1NuYXBzaHRvdCBleGlzdHMgP31cbiAgICAgICAgY2hlY2tTbmFwIC0tPiB8WWVzfCBkb3dubG9hZChbRG93bmxvYWRdKVxuICAgICAgICBjaGVja1NuYXAgLS0-IHxOb3wgZHVtcChbRHVtcF0pIC0tPiBleGl0W0V4aXRdOjo6Z3JlZW5cbiAgICAgZW5kIiwibWVybWFpZCI6e30sInVwZGF0ZUVkaXRvciI6ZmFsc2V9)

## Todo

-   [ ] Optional restic backup arguments
-   [ ] Start manual backup

## Requirements

-   Git
-   Docker / docker-compose

## Local installation - execute once

-   Install dependencies, configure environments, run containers:

```shell
cp -f .env .env.local
```

-   Launch php + database + assets

```shell
./start-dev.sh
```

> If errors pop up because you already have containers using required ports, you can stop all running containers with `docker stop $(docker ps -aq)`

-   Create database and load fixtures:

```shell
docker-compose exec php bin/console doctrine:database:create
docker-compose exec php bin/console doctrine:migrations:migrate
docker-compose exec php bin/console hautelook:fixtures:load --env=dev
```

## Development environment

### Launch the development environment

```shell
./start-dev.sh
```

### Access to your containers

-   Mailhog : http://localhost:8025/
-   phpPgAdmin : http://localhost:9080/
-   Webapp : http://localhost/

### Start backups

```shell
docker-compose exec php bin/console app:backup:start
```

### Useful commands - Symfony with docker cheatsheet

See the [Symfony with docker cheatsheet](doc/symfony-docker.md)

### To update graph in README.md

```
docker-compose run php bash -c "bin/console workflow:dump backup | dot -Tsvg -o doc/graph.svg"
```
