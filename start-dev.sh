#!/usr/bin/env bash

set -e

docker-compose stop
docker-compose up --build -d
