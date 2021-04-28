#!/bin/bash

export UID=$(id -u)
export GID=$(id -g)

export DOCKER_BUILDKIT=0

docker-compose stop
docker-compose up --build
