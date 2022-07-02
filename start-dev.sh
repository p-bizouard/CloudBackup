#!/usr/bin/env bash

set -e

declare DOCKER_USER
DOCKER_USER="$(id -u):$(id -g)"

#export DOCKER_BUILDKIT=0

DOCKER_USER=${DOCKER_USER} docker-compose stop
DOCKER_USER=${DOCKER_USER} docker-compose up --build
