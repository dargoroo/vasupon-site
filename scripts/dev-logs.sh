#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/.."
docker compose --env-file .env.docker logs -f app db phpmyadmin
