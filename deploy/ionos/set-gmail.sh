#!/usr/bin/env bash
# Sends OTP emails through Gmail SMTP. The Gmail App Password is read at a hidden prompt and
# written to .env only (never echoed, logged or passed as an argument). Then sends a test email.
set -euo pipefail
APP_DIR="${APP_DIR:-$HOME/femalerider-api}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.4-cli}"
GMAIL="${1:?usage: set-gmail.sh <your-gmail-address>}"
read -rsp "Paste the 16-letter Gmail App Password (hidden), then Enter: " APP_PW; echo
APP_PW="$APP_PW" GMAIL="$GMAIL" APP_DIR="$APP_DIR" "$PHP_BIN" -r '
  $f = getenv("APP_DIR")."/.env";
  $s = file_get_contents($f);
  $set = function ($k, $v) use (&$s) {
    $line = $k."=".$v;
    $s = preg_match("/^".$k."=.*$/m", $s) ? preg_replace_callback("/^".$k."=.*$/m", fn () => $line, $s) : rtrim($s)."\n".$line."\n";
  };
  $mail = strtolower(preg_replace("/[^A-Za-z0-9@._+\-]/", "", getenv("GMAIL")));
  $set("MAIL_MAILER", "smtp");
  $set("MAIL_SCHEME", "null");
  $set("MAIL_HOST", "smtp.gmail.com");
  $set("MAIL_PORT", "587");
  $set("MAIL_USERNAME", $mail);
  $set("MAIL_PASSWORD", preg_replace("/[^A-Za-z]/", "", getenv("APP_PW")));
  $set("MAIL_FROM_ADDRESS", "\"".$mail."\"");
  $set("MAIL_FROM_NAME", "\"FemaleRider\"");
  file_put_contents($f, $s); chmod($f, 0600);'
unset APP_PW
"$PHP_BIN" "$APP_DIR/artisan" config:cache >/dev/null
GMAIL="$GMAIL" "$PHP_BIN" "$APP_DIR/artisan" tinker --execute='Illuminate\Support\Facades\Mail::to(getenv("GMAIL"))->send(new App\Mail\OtpCodeMail("123456", "login", 5)); echo "OK: test email sent to ".getenv("GMAIL")." - check the inbox. You can close this tab.\n";'
