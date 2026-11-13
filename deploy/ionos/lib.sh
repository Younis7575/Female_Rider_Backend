#!/usr/bin/env bash
# Shared helpers for the IONOS deploy scripts.
set -euo pipefail

APP_DIR="${APP_DIR:-$HOME/femalerider-api}"
REPO_URL="${REPO_URL:-https://github.com/Younis7575/Female_Rider_Backend.git}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-production}"

# IONOS ships several PHP CLIs; prefer the web version (8.4).
detect_php() {
  if [[ -n "${PHP_BIN:-}" ]]; then echo "$PHP_BIN"; return; fi
  # Match the web PHP version configured for the subdomain (IONOS: 8.4).
  for v in 8.4 8.5; do
    for c in "/usr/bin/php${v}-cli" "php${v}-cli" "php${v}"; do
      if command -v "$c" >/dev/null 2>&1; then command -v "$c"; return; fi
    done
  done
  echo php
}

PHP="$(detect_php)"

ensure_composer() {
  local bin="$HOME/bin/composer.phar"
  if [[ ! -f "$bin" ]]; then
    mkdir -p "$HOME/bin"
    local expected actual
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o "$HOME/bin/composer-setup.php"
    actual="$("$PHP" -r "echo hash_file('sha384', '$HOME/bin/composer-setup.php');")"
    if [[ "$expected" != "$actual" ]]; then rm -f "$HOME/bin/composer-setup.php"; echo "Composer installer checksum mismatch" >&2; exit 1; fi
    "$PHP" "$HOME/bin/composer-setup.php" --quiet --install-dir="$HOME/bin" --filename=composer.phar
    rm -f "$HOME/bin/composer-setup.php"
  fi
  echo "$bin"
}

composer_install() {
  local composer; composer="$(ensure_composer)"
  COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1 "$PHP" -d memory_limit=-1 "$composer" install \
    --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --working-dir="$APP_DIR"
}

artisan() { "$PHP" "$APP_DIR/artisan" "$@"; }

optimize_app() {
  artisan migrate --force
  artisan storage:link >/dev/null 2>&1 || true
  artisan optimize:clear >/dev/null
  artisan config:cache
  artisan route:cache
  artisan view:cache
  artisan event:cache
  artisan filament:assets >/dev/null 2>&1 || true
  artisan filament:optimize >/dev/null 2>&1 || true
  artisan queue:restart >/dev/null 2>&1 || true
}
