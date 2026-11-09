#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Optional real PostgreSQL integration check. Requires an isolated disposable database. */
require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;

if (getenv('CAJEER_PG_TEST') !== '1') {
    fwrite(STDERR, "SKIP: set CAJEER_PG_TEST=1 and configure a disposable cajeer_test_* database.\n");
    exit(77);
}
$name = getenv('CAJEER_PG_TEST_DATABASE') ?: '';
if (!preg_match('/^cajeer_test_[a-z0-9_]+$/D', $name)) {
    fwrite(STDERR, "REFUSED: CAJEER_PG_TEST_DATABASE must be a dedicated cajeer_test_* database.\n");
    exit(2);
}
$base = sys_get_temp_dir() . '/cajeer-pg-integration-' . bin2hex(random_bytes(8));
mkdir($base, 0700, true);
try {
    // Password is read from the process environment; never persisted to disk.
    file_put_contents($base . '/database.php', <<<'CFG'
<?php
return ['default' => 'pgsql', 'connections' => ['pgsql' => [
    'driver' => 'pgsql',
    'host' => getenv('CAJEER_PG_TEST_HOST') ?: '127.0.0.1',
    'port' => getenv('CAJEER_PG_TEST_PORT') ?: 5432,
    'database' => getenv('CAJEER_PG_TEST_DATABASE'),
    'username' => getenv('CAJEER_PG_TEST_USERNAME') ?: 'cajeer',
    'password' => getenv('CAJEER_PG_TEST_PASSWORD') ?: '',
    'sslmode' => getenv('CAJEER_PG_TEST_SSLMODE') ?: 'prefer',
]]];
CFG);
    chmod($base . '/database.php', 0600);
    $manager = new DatabaseManager(new ConfigRepository($base), new ConnectionFactory());
    $pdo = $manager->connection();
    $serverVersion = (int) $pdo->query("SELECT current_setting('server_version_num')")?->fetchColumn();
    if ($serverVersion < 170000) throw new RuntimeException('PostgreSQL 17+ is required.');
    $runner = new MigrationRunner($manager, dirname(__DIR__) . '/migrations/pgsql');
    $first = $runner->run();
    $second = $runner->run();
    if (!$runner->validate()['valid'] || $second['applied'] !== [] || count($runner->status()) !== 2) {
        throw new RuntimeException('PostgreSQL migration journal is invalid or rerun is not idempotent.');
    }
    foreach (['users', 'roles', 'user_roles', 'role_permissions', 'api_tokens', 'inventory_assets', 'cajeer_schema_migrations'] as $table) {
        $sql = $pdo->prepare('SELECT to_regclass(:name)');
        $sql->execute(['name' => $table]);
        if (!$sql->fetchColumn()) throw new RuntimeException('Expected PostgreSQL table missing: ' . $table);
    }
    // Verify that a SQL error rolls back both DDL and migration journal insertion.
    $migrationDir = $base . '/migrations';
    mkdir($migrationDir, 0700);
    foreach (glob(dirname(__DIR__) . '/migrations/pgsql/*.sql') as $file) {
        copy($file, $migrationDir . '/' . basename($file));
    }
    file_put_contents($migrationDir . '/0003_rollback_probe.sql', "CREATE TABLE cajeer_test_rollback_probe(id integer);\nSELECT non_existing_function_probe();\n");
    try {
        (new MigrationRunner($manager, $migrationDir))->run();
        throw new RuntimeException('Expected probe SQL to fail.');
    } catch (PDOException) {
        // Expected PostgreSQL failure; the transaction must be rolled back.
    }
    $exists = $pdo->query("SELECT to_regclass('cajeer_test_rollback_probe')")?->fetchColumn();
    $journal = $pdo->query("SELECT count(*) FROM cajeer_schema_migrations WHERE version='0003_rollback_probe'")?->fetchColumn();
    if ($exists !== null || (int) $journal !== 0) {
        throw new RuntimeException('Failed SQL was not rolled back atomically.');
    }
    echo "PASS: real PostgreSQL integration, " . implode(', ', $first['applied']) . ", idempotency, schema, rollback.\n";
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] PostgreSQL integration: ' . $error::class . "; see test DB/logs.\n");
    exit(1);
} finally {
    if (is_dir($base . '/migrations')) {
        foreach (glob($base . '/migrations/*.sql') ?: [] as $file) unlink($file);
        rmdir($base . '/migrations');
    }
    @unlink($base . '/database.php');
    @rmdir($base);
}
