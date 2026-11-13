<?php

// Stores DB_PASSWORD (from the environment) in .env and verifies the connection with PDO.
// Usage: DB_PASSWORD=... php set-db-password.php /path/to/.env
$file = $argv[1] ?? '';
$password = (string) getenv('DB_PASSWORD');
if (! is_file($file) || $password === '') {
    fwrite(STDERR, "Nothing to do (missing .env or empty password)\n");
    exit(1);
}
$quoted = '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $password).'"';
$env = preg_replace_callback('/^DB_PASSWORD=.*$/m', static fn () => 'DB_PASSWORD='.$quoted, file_get_contents($file));
file_put_contents($file, $env);
chmod($file, 0600);

$get = static function (string $key) use ($env): string {
    return preg_match('/^'.$key.'=(.*)$/m', $env, $m) ? trim($m[1], "\"' ") : '';
};
try {
    new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', $get('DB_HOST'), $get('DB_PORT') ?: '3306', $get('DB_DATABASE')), $get('DB_USERNAME'), $password, [PDO::ATTR_TIMEOUT => 10]);
    echo "OK: database connection works. You can close this tab.\n";
} catch (Throwable $e) {
    echo "Could not connect with that password. Run the command again and re-type it.\n";
    exit(2);
}
