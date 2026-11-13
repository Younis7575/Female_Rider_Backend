#!/usr/bin/env bash
# One-time server setup on IONOS (run over SSH from the webspace home directory):
#   bash <(curl -fsSL https://raw.githubusercontent.com/Younis7575/Female_Rider_Backend/main/deploy/ionos/bootstrap.sh)
# Creates ~/femalerider-api from the "production" branch, writes .env (database password is
# asked at a hidden prompt and never echoed), migrates, seeds roles/config and caches config.
set -euo pipefail
APP_DIR="${APP_DIR:-$HOME/femalerider-api}"
REPO_URL="${REPO_URL:-https://github.com/Younis7575/Female_Rider_Backend.git}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-production}"

if [[ ! -d "$APP_DIR/.git" ]]; then
  git clone --branch "$DEPLOY_BRANCH" --single-branch "$REPO_URL" "$APP_DIR"
fi
# shellcheck source=/dev/null
source "$APP_DIR/deploy/ionos/lib.sh"
echo "Using PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"

if [[ ! -f "$APP_DIR/.env" ]]; then
  cp "$APP_DIR/deploy/ionos/env.production.example" "$APP_DIR/.env"
  chmod 600 "$APP_DIR/.env"
  read -rp "MySQL host (from IONOS → Databases): " DB_HOST
  read -rp "MySQL database name: " DB_DATABASE
  read -rp "MySQL user name: " DB_USERNAME
  read -rsp "MySQL password (hidden): " DB_PASSWORD; echo
  read -rp "QA test phone numbers, E.164, comma separated (optional): " OTP_NUMBERS
  if [[ -n "$OTP_NUMBERS" ]]; then read -rsp "6-digit QA OTP code (hidden): " OTP_CODE; echo; else OTP_CODE=""; fi
  DB_HOST="$DB_HOST" DB_DATABASE="$DB_DATABASE" DB_USERNAME="$DB_USERNAME" DB_PASSWORD="$DB_PASSWORD" \
    OTP_NUMBERS="$OTP_NUMBERS" OTP_CODE="$OTP_CODE" "$PHP" "$APP_DIR/deploy/ionos/write-env.php" "$APP_DIR/.env"
  unset DB_PASSWORD OTP_CODE
fi

mkdir -p "$APP_DIR/storage/app/private/firebase" "$APP_DIR/storage/logs"
composer_install
grep -q '^APP_KEY=base64' "$APP_DIR/.env" || artisan key:generate --force
artisan migrate --force
artisan db:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force
artisan db:seed --class='Database\Seeders\PlatformConfigSeeder' --force
optimize_app
git -C "$APP_DIR" rev-parse HEAD > "$APP_DIR/REVISION"

cat <<MSG

FemaleRider is installed in $APP_DIR (revision $(cat "$APP_DIR/REVISION")).
Next:
  1. Create your admin login:   $PHP $APP_DIR/artisan fr:create-admin you@example.com --name="Your Name"
  2. IONOS → Cron jobs (every minute):
       $PHP $APP_DIR/artisan schedule:run
       bash $APP_DIR/deploy/ionos/pull-deploy.sh
  3. Copy the Firebase service account to $APP_DIR/storage/app/private/firebase/service-account.json (chmod 600).
MSG
