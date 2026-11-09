#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Run ONLY on an empty, disposable PostgreSQL 17/18 database created for this test.
 * Example:
 * CAJEER_INSTALL_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_installer_test \
 * CAJEER_PG_TEST_USERNAME=... CAJEER_PG_TEST_PASSWORD=... php tests/installer-postgres-integration.php
 */
if (getenv('CAJEER_INSTALL_PG_TEST') !== '1') {
    fwrite(STDERR, "Not run. Explicitly set CAJEER_INSTALL_PG_TEST=1 and use a disposable test DB.\n");
    exit(2);
}
if (!extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "ext-pdo_pgsql required.\n");
    exit(2);
}
require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Installer\InstallService;
use Cajeer\Servers\Config\EnvironmentLoader;

$dbName = EnvironmentLoader::getString('CAJEER_PG_TEST_DATABASE');
if (!preg_match('/^[a-z0-9_]{1,63}_test$/D', $dbName)) {
    fwrite(STDERR, "Database name must end with _test; never run on a production DB.\n");
    exit(2);
}
$host = EnvironmentLoader::getString('CAJEER_PG_TEST_HOST','127.0.0.1');
$port = EnvironmentLoader::getString('CAJEER_PG_TEST_PORT','5432');
$user = EnvironmentLoader::getString('CAJEER_PG_TEST_USERNAME');
$pass = EnvironmentLoader::getString('CAJEER_PG_TEST_PASSWORD');
$sslmode = EnvironmentLoader::getString('CAJEER_PG_TEST_SSLMODE','prefer');
if ($user === '') { fwrite(STDERR, "CAJEER_PG_TEST_USERNAME missing.\n"); exit(2); }
$root = sys_get_temp_dir() . '/cajeer-pg-install-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true);
mkdir($root . '/storage/app', 0700, true);
mkdir($root . '/migrations/pgsql', 0700, true);
copy(dirname(__DIR__) . '/.env.example', $root . '/.env.example');
foreach (glob(dirname(__DIR__) . '/migrations/pgsql/*.sql') as $sql) copy($sql, $root . '/migrations/pgsql/' . basename($sql));
$remove = static function (string $dir) use (&$remove): void {
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $dir . '/' . $name;
        if (is_dir($path) && !is_link($path)) $remove($path);
        else unlink($path);
    }
    rmdir($dir);
};
try {
    $service = new InstallService($root);
    $input = [
        'APP_ENV' => 'local', 'APP_URL' => 'http://127.0.0.1:8080', 'APP_KEY' => '',
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $host, 'DB_PORT' => $port,
        'DB_DATABASE' => $dbName, 'DB_USERNAME' => $user, 'DB_PASSWORD' => $pass,
        'DB_SSLMODE' => $sslmode, 'DB_SSLROOTCERT' => '',
        'admin_email' => 'installer-test@example.invalid', 'admin_name' => 'Installer Integration',
        'admin_password' => 'safe integration administrator password',
    ];
    $factory = new \Cajeer\Servers\Database\ConnectionFactory();
    $pdo = $factory->make([
        'driver' => 'pgsql', 'host' => $host, 'port' => $port, 'database' => $dbName,
        'username' => $user, 'password' => $pass, 'sslmode' => $sslmode,
    ]);
    $userTable = $pdo->query("SELECT to_regclass('users')")->fetchColumn();
    if ($userTable !== null && $userTable !== false) throw new RuntimeException('Test database is not empty; refusing to run.');
    $result = $service->install($input);
    if (!$result['installed'] || !$service->state()->isInstalled()) throw new RuntimeException('Installation did not finish.');
    if (count($result['migrations']) < 3) throw new RuntimeException('Expected migrations were not executed.');
    $stmt = $pdo->prepare('SELECT u.email,u.password_hash,r.slug FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON ur.role_id=r.id WHERE u.email=:email');
    $stmt->execute(['email' => $input['admin_email']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row['slug'] !== 'administrator'
        || !password_verify($input['admin_password'], (string) $row['password_hash'])
        || password_get_info((string) $row['password_hash'])['algoName'] !== 'argon2id') {
        throw new RuntimeException('Administrator provisioning failed.');
    }
    $record = $pdo->query('SELECT count(*) FROM cajeer_installation')->fetchColumn();
    if ((int) $record !== 1) throw new RuntimeException('Installation marker missing from PostgreSQL.');
    $rejected = false;
    try { $service->install($input); } catch (RuntimeException) { $rejected = true; }
    if (!$rejected || (int) $pdo->query('SELECT count(*) FROM users')->fetchColumn() !== 1) {
        throw new RuntimeException('Repeated installation was not rejected.');
    }
    unlink($root . '/storage/app/installed.json');
    $restored = $service->recover();
    if (!$restored['installed']) throw new RuntimeException('Could not recover marker from DB.');
    echo "PASS: PostgreSQL migrations, Argon2id administrator, RBAC relation, installation marker, duplicate protection, recovery.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[FAIL] " . $e->getMessage() . "\n");
    exit(1);
} finally {
    $remove($root);
}
