#!/usr/bin/env bash
set -euo pipefail

# Review and adjust these paths before running on the remote host.
OLD_LARAVEL_ROOT="/opt/my-grader"
OLD_SERVICE_NAME="my-grader"
OLD_CONTAINER_MATCH="my-grader"

echo "[cleanup] stopping old systemd service if it exists"
if systemctl list-unit-files | grep -q "^${OLD_SERVICE_NAME}"; then
  sudo systemctl stop "${OLD_SERVICE_NAME}" || true
  sudo systemctl disable "${OLD_SERVICE_NAME}" || true
fi

echo "[cleanup] removing old docker containers that match ${OLD_CONTAINER_MATCH}"
docker ps -a --format '{{.ID}} {{.Names}}' | awk '$2 ~ /'"${OLD_CONTAINER_MATCH}"'/ { print $1 }' | while read -r container_id; do
  [ -n "${container_id}" ] && docker rm -f "${container_id}" || true
done

echo "[cleanup] archiving old project directory if it exists"
if [ -d "${OLD_LARAVEL_ROOT}" ]; then
  sudo mv "${OLD_LARAVEL_ROOT}" "${OLD_LARAVEL_ROOT}.bak.$(date +%Y%m%d-%H%M%S)"
fi

echo "[cleanup] done"
