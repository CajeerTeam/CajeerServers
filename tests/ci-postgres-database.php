<?php
declare(strict_types=1);
// CI-only database provisioning. Never operates on the configured source database.
if (getenv('CI') !== 'true' || getenv('CAJEER_PG_TEST') !== '1' ||
    !preg_match('/^cajeer_test_[a-z0-9_]+$/D', getenv('CAJEER_PG_TEST_DATABASE') ?: '') ||
    $argc !== 3 || !in_array($argv[1], ['create','drop'], true) ||
    !preg_match('/^cajeer_test_ci_[0-9a-f]{16}$/D', $argv[2]) ||
    $argv[2] === getenv('CAJEER_PG_TEST_DATABASE')) {
    fwrite(STDERR, "Refused unsafe CI database operation.\n"); exit(2);
}
try {
    $host = getenv('CAJEER_PG_TEST_HOST') ?: '127.0.0.1';
    $port = getenv('CAJEER_PG_TEST_PORT') ?: '5432';
    $ssl = getenv('CAJEER_PG_TEST_SSLMODE') ?: 'disable';
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=postgres;sslmode=$ssl", getenv('CAJEER_PG_TEST_USERNAME') ?: 'cajeer', getenv('CAJEER_PG_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $name = '"'.$argv[2].'"';
    $pdo->exec($argv[1] === 'create' ? "CREATE DATABASE $name TEMPLATE template0" : "DROP DATABASE $name WITH (FORCE)");
} catch (Throwable $e) { fwrite(STDERR, 'CI database operation failed: '.$e::class.' '.$e->getCode()."\n"); exit(1); }
