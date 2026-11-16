#!/usr/bin/env bash
# Switches guardian alerts to the Meta WhatsApp Cloud API. The access token is read at a
# hidden prompt and written to .env only (never echoed, logged or passed as an argument).
set -euo pipefail
APP_DIR="${APP_DIR:-$HOME/femalerider-api}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.4-cli}"
PHONE_ID="${1:?usage: set-whatsapp.sh <phone-number-id>}"
read -rsp "Paste the WhatsApp permanent access token (hidden), then Enter: " WA_TOKEN; echo
WA_TOKEN="$WA_TOKEN" PHONE_ID="$PHONE_ID" "$PHP_BIN" -r '
  $f = getenv("APP_DIR") ?: getenv("HOME")."/femalerider-api"; $f .= "/.env";
  $s = file_get_contents($f);
  $set = function ($k, $v) use (&$s) {
    $line = $k."=".$v;
    $s = preg_match("/^".$k."=.*$/m", $s) ? preg_replace_callback("/^".$k."=.*$/m", fn () => $line, $s) : rtrim($s)."\n".$line."\n";
  };
  $set("WHATSAPP_DRIVER", "cloud");
  $set("WHATSAPP_PHONE_NUMBER_ID", preg_replace("/\D/", "", getenv("PHONE_ID")));
  $set("WHATSAPP_ACCESS_TOKEN", preg_replace("/[^A-Za-z0-9_\-]/", "", getenv("WA_TOKEN")));
  file_put_contents($f, $s); chmod($f, 0600);'
unset WA_TOKEN
"$PHP_BIN" "$APP_DIR/artisan" config:cache >/dev/null
echo "OK: WhatsApp Cloud API enabled. You can close this tab."
