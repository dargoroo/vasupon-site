#!/usr/bin/env bash
set -euo pipefail

SYSTEMD_DIR="${SYSTEMD_DIR:-/etc/systemd/system}"
WORKER_TEMPLATE_SOURCE="${WORKER_TEMPLATE_SOURCE:-./grader-worker@.service}"
WORKER_TARGET_SOURCE="${WORKER_TARGET_SOURCE:-./grader-workers.target}"
SINGLE_SERVICE_NAME="${SINGLE_SERVICE_NAME:-grader-worker.service}"
POOL_TARGET_NAME="${POOL_TARGET_NAME:-grader-workers.target}"

install -m 0644 "$WORKER_TEMPLATE_SOURCE" "$SYSTEMD_DIR/grader-worker@.service"
install -m 0644 "$WORKER_TARGET_SOURCE" "$SYSTEMD_DIR/grader-workers.target"

systemctl daemon-reload

if systemctl list-unit-files | grep -q "^${SINGLE_SERVICE_NAME}"; then
    systemctl disable --now "$SINGLE_SERVICE_NAME" || true
fi

systemctl enable --now grader-worker@1.service
systemctl enable --now grader-worker@2.service
systemctl enable --now "$POOL_TARGET_NAME"

systemctl status grader-worker@1.service --no-pager || true
systemctl status grader-worker@2.service --no-pager || true
