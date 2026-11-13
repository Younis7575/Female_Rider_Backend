#!/usr/bin/env bash
# Continuous deployment for IONOS shared hosting (run by cron every minute).
# GitHub Actions only promotes commits that passed CI to the "production" branch; this
# script notices a new production commit, installs it and caches the app. No secrets are
# stored in GitHub and the server needs no inbound access.
set -euo pipefail
APP_DIR="${APP_DIR:-$HOME/femalerider-api}"
# shellcheck source=/dev/null
source "$APP_DIR/deploy/ionos/lib.sh"
LOG="$APP_DIR/storage/logs/deploy.log"
LOCK="$APP_DIR/storage/framework/deploy.lock"

mkdir -p "$(dirname "$LOCK")"
if ! mkdir "$LOCK" 2>/dev/null; then
  # Stale lock (older than 15 minutes) → remove, otherwise another deploy is running.
  if [[ -n "$(find "$LOCK" -maxdepth 0 -mmin +15 2>/dev/null)" ]]; then rmdir "$LOCK"; mkdir "$LOCK"; else exit 0; fi
fi
trap 'rmdir "$LOCK" 2>/dev/null || true' EXIT

cd "$APP_DIR"
git fetch --quiet origin "$DEPLOY_BRANCH"
CURRENT="$(git rev-parse HEAD)"
TARGET="$(git rev-parse "origin/$DEPLOY_BRANCH")"
[[ "$CURRENT" == "$TARGET" && "${FORCE_DEPLOY:-0}" != "1" ]] && exit 0

{
  echo "=== $(date -u +%FT%TZ) deploying ${TARGET:0:12} (was ${CURRENT:0:12})"
  if ! (
    set -e
    artisan down --retry=15 --refresh=15 >/dev/null 2>&1 || true
    OLD_LOCK="$(git rev-parse "$CURRENT:composer.lock" 2>/dev/null || true)"
    git reset --hard --quiet "$TARGET"
    # lib.sh may have changed with the new release
    source "$APP_DIR/deploy/ionos/lib.sh"
    if [[ "$OLD_LOCK" != "$(git rev-parse HEAD:composer.lock)" || ! -f "$APP_DIR/vendor/autoload.php" ]]; then
      composer_install
    else
      echo "composer.lock unchanged — skipping composer install"
    fi
    optimize_app
    echo "$TARGET" > "$APP_DIR/REVISION"
  ); then
    echo "!!! deploy failed — rolling back to ${CURRENT:0:12}"
    git reset --hard --quiet "$CURRENT"
    composer_install || true
    optimize_app || true
    artisan up || true
    exit 1
  fi
  artisan up
  echo "=== done $(date -u +%FT%TZ)"
} >> "$LOG" 2>&1
