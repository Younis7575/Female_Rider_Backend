#!/usr/bin/env bash
# Asks for the database password at a hidden prompt and stores it in .env (never echoed or logged).
set -euo pipefail
APP_DIR="${APP_DIR:-$HOME/femalerider-api}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.4-cli}"
read -rsp "Database password (typing is hidden), then Enter: " DBPW; echo
DB_PASSWORD="$DBPW" "$PHP_BIN" "$APP_DIR/deploy/ionos/set-db-password.php" "$APP_DIR/.env"
