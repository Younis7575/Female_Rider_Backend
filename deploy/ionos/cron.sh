#!/usr/bin/env bash
# Single IONOS cron entry (UnixCron, every minute). IONOS only accepts a bare script path.
#   1. continuous deployment: install a new "production" release if GitHub promoted one
#   2. Laravel scheduler (dispatch ticks, sweeps, queue worker for ~55 s)
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
export APP_DIR HOME="${HOME:-$(cd "$APP_DIR/.." && pwd)}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.4-cli}"
bash "$APP_DIR/deploy/ionos/pull-deploy.sh" >/dev/null 2>&1 || true
exec "$PHP_BIN" "$APP_DIR/artisan" schedule:run >> "$APP_DIR/storage/logs/scheduler.log" 2>&1
