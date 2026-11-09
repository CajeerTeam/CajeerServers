#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Real PostgreSQL 17+ upgrade and non-destructive legacy-data test; disposable test schema. */
require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Inventory\InventoryReadiness;

if (getenv('CAJEER_PG_TEST') !== '1' ||
    !preg_match('/^cajeer_test_[a-z0-9_]+$/D', getenv('CAJEER_PG_TEST_DATABASE') ?: '')) {
    fwrite(STDERR, "REFUSED: use CAJEER_PG_TEST=1 with isolated cajeer_test_* PostgreSQL database.\n");
    exit(2);
}
if (!extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "FAIL: ext-pdo_pgsql is required for real integration test.\n");
    exit(1);
}
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().'/cajeer-inventory-pg-'.bin2hex(random_bytes(7));
$schema = 'cajeer_inv_' . bin2hex(random_bytes(8));
$schemaCreated = false;
$pdo = null;
mkdir($tmp.'/migrations',0700,true);
try {
    $manager=new DatabaseManager(new ConfigRepository($root.'/config'),new ConnectionFactory(),[
        'driver'=>'pgsql', 'host'=>getenv('CAJEER_PG_TEST_HOST') ?: '127.0.0.1',
        'port'=>getenv('CAJEER_PG_TEST_PORT') ?: '5432',
        'database'=>getenv('CAJEER_PG_TEST_DATABASE'),
        'username'=>getenv('CAJEER_PG_TEST_USERNAME') ?: 'cajeer',
        'password'=>getenv('CAJEER_PG_TEST_PASSWORD') ?: '',
        'sslmode'=>getenv('CAJEER_PG_TEST_SSLMODE') ?: 'prefer',
    ]);
    $pdo=$manager->connection();
    $version=(int)$pdo->query("SELECT current_setting('server_version_num')")?->fetchColumn();
    if ($version < 170000) throw new RuntimeException('PostgreSQL 17+ required');
    // Keep pgcrypto in public so DROP SCHEMA cannot cascade into a DB-level extension.
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public');
    $pdo->exec('CREATE SCHEMA "' . $schema . '"');
    $schemaCreated=true;
    $pdo->exec('SET search_path TO "'.$schema.'", public');
    foreach (glob($root.'/migrations/pgsql/000[1-7]_*.sql') as $file) {
        if (!copy($file,$tmp.'/migrations/'.basename($file))) throw new RuntimeException('Cannot stage baseline SQL');
    }
    $runner = new MigrationRunner($manager, $tmp.'/migrations');
    $runner->run();
    $pdo->exec("INSERT INTO inventory_assets(type,source,name,status,tags,ip_addresses,attributes) "
        . "VALUES ('server','manual','','unknown','{}'::jsonb,'[]'::jsonb,'[]'::jsonb)");
    $before=$pdo->query('SELECT count(*) FROM inventory_assets')?->fetchColumn();
    copy($root.'/migrations/pgsql/0008_inventory_readiness.sql',$tmp.'/migrations/0008_inventory_readiness.sql');
    $runner->run();
    $after=$pdo->query('SELECT count(*) FROM inventory_assets')?->fetchColumn();
    if ((int)$before !== 1 || (int)$after !== 1) throw new RuntimeException('Upgrade must not discard legacy inventory data');
    $service=new InventoryReadiness($manager);
    if ($service->inspect()['ok'] !== false) throw new RuntimeException('Legacy invalid data not detected');
    try {
        $pdo->exec("INSERT INTO inventory_assets(type,source,name,tags,ip_addresses,attributes) VALUES ('server','manual','bad','{}'::jsonb,'[]'::jsonb,'{}'::jsonb)");
        throw new RuntimeException('New malformed JSON must be rejected by PostgreSQL CHECK');
    } catch (PDOException $error) {
        if ($error->getCode() !== '23514') throw $error;
    }
    $pdo->exec("UPDATE inventory_assets SET name='corrected legacy name',tags='[]'::jsonb,attributes='{}'::jsonb");
    if (!$service->inspect()['ok']) throw new RuntimeException('Corrected legacy row still invalid');
    $result=$service->validateConstraints();
    if (count($result['validated']) !== 8 || !$service->inspect(true)['ok']) {
        throw new RuntimeException('Database constraints were not validated');
    }
    if ($runner->run()['applied'] !== []) throw new RuntimeException('Upgrade was not idempotent');
    if (!$runner->validate()['valid']) throw new RuntimeException('Migration checksums invalid');
    echo "PASS: PostgreSQL inventory upgrade preserved legacy rows, enforced constraints, validated and reran idempotently.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'FAIL: inventory PostgreSQL upgrade: '.$error::class.".\n");
    exit(1);
} finally {
    if ($schemaCreated && $pdo instanceof PDO) {
        try { $pdo->exec('SET search_path TO public'); $pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE'); }
        catch (Throwable) { fwrite(STDERR,"WARNING: disposable test schema cleanup failed.\n"); }
    }
    foreach (glob($tmp.'/migrations/*.sql') ?: [] as $file) @unlink($file);
    @rmdir($tmp.'/migrations');@rmdir($tmp);
}
