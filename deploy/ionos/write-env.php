<?php

// Fills the placeholders in .env from environment variables set by bootstrap.sh.
// Usage: DB_HOST=… DB_DATABASE=… DB_USERNAME=… DB_PASSWORD=… php write-env.php /path/.env
$file = $argv[1] ?? '';
if (! is_file($file)) {
    fwrite(STDERR, "Missing .env file\n");
    exit(1);
}
$quote = static fn (string $v): string => '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $v).'"';
$env = file_get_contents($file);
$env = strtr($env, [
    '__DB_HOST__' => (string) getenv('DB_HOST'),
    '__DB_DATABASE__' => (string) getenv('DB_DATABASE'),
    '__DB_USERNAME__' => (string) getenv('DB_USERNAME'),
    '__DB_PASSWORD__' => $quote((string) getenv('DB_PASSWORD')),
]);
$env = preg_replace('/^OTP_TEST_NUMBERS=.*$/m', 'OTP_TEST_NUMBERS='.preg_replace('/[^+0-9,]/', '', (string) getenv('OTP_NUMBERS')), $env);
$env = preg_replace('/^OTP_TEST_CODE=.*$/m', 'OTP_TEST_CODE='.preg_replace('/\D/', '', (string) getenv('OTP_CODE')), $env);
file_put_contents($file, $env);
chmod($file, 0600);
echo "Wrote $file\n";
