#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Live isolated pg_dump -> libsodium -> pg_restore disaster recovery integration. */
require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Backup\BackupKey;
use Cajeer\Servers\Backup\BackupService;
use Cajeer\Servers\Kernel\Application;

$source = getenv('CAJEER_PG_TEST_DATABASE') ?: '';
if (getenv('CAJEER_PG_TEST') !== '1' || !preg_match('/^cajeer_test_[a-z0-9_]+$/D', $source)) {
    fwrite(STDERR, "REFUSED: Use CAJEER_PG_TEST=1 and an isolated cajeer_test_* source DB.\n"); exit(2);
}
if (!extension_loaded('pdo_pgsql') || !extension_loaded('sodium')) {
    fwrite(STDERR,"FAIL: ext-pdo_pgsql and ext-sodium are mandatory for live recovery integration.\n"); exit(1);
}
$root = dirname(__DIR__);
$dir = sys_get_temp_dir() . '/cajeer-backup-pg-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
$target = 'cajeer_test_dr_' . bin2hex(random_bytes(6));
$schema = 'cajeer_dr_' . bin2hex(random_bytes(6));
$restoreStorage = $dir . '/restored-storage';
$restoreRecovery = $dir . '/restored-recovery';
$sourcePdo = null; $adminPdo = null; $targetPdo = null; $app = null; $createdDatabase = false; $createdSchema = false; $failed = false;
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
};
$reject = static function (callable $callback, string $message) use ($assert): void {
    try { $callback(); } catch (Throwable) { $assert(true, $message); return; }
    throw new RuntimeException('FAIL: should have rejected ' . $message);
};
$delete = static function (string $path) use (&$delete): void {
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') $delete($path . '/' . $name);
    @rmdir($path);
};
$changed = [];
foreach (['DB_CONNECTION','DB_DATABASE','DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD','DB_SSLMODE',
          'APP_ENV','APP_URL','APP_KEY','VAULT_MASTER_KEY','VAULT_MASTER_KEY_FILE','STORAGE_DISK','STORAGE_LOCAL_ROOT'] as $key) {
    $changed[$key] = getenv($key);
}
try {
    $host = getenv('CAJEER_PG_TEST_HOST') ?: '127.0.0.1';
    $port = getenv('CAJEER_PG_TEST_PORT') ?: '5432';
    $user = getenv('CAJEER_PG_TEST_USERNAME') ?: 'cajeer';
    $pass = getenv('CAJEER_PG_TEST_PASSWORD') ?: '';
    $sslmode = getenv('CAJEER_PG_TEST_SSLMODE') ?: 'disable';
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    $dsn = static fn(string $db):string => sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $host, $port, $db, $sslmode);
    $sourcePdo = new PDO($dsn($source), $user, $pass, $options);
    $adminPdo = new PDO($dsn('postgres'), $user, $pass, $options);
    $assert((int)$sourcePdo->query("SELECT current_setting('server_version_num')")->fetchColumn() >= 170000,'PostgreSQL 17+');
    $adminPdo->exec('CREATE DATABASE "' . $target . '"'); $createdDatabase = true;
    $sourcePdo->exec('CREATE SCHEMA "' . $schema . '"'); $createdSchema = true;
    $sourcePdo->exec('CREATE TABLE "' . $schema . '".dr_records(id integer PRIMARY KEY, message text NOT NULL)');
    $sourcePdo->exec("INSERT INTO \"$schema\".dr_records(id,message) VALUES (1,'Cajeer DR test payload')");

    $storage = $dir . '/live-storage'; mkdir($storage, 0700);
    mkdir($storage . '/nested', 0700);
    $blob = random_bytes(450000);
    file_put_contents($storage . '/nested/test.bin', $blob);
    $keyFile = $dir . '/backup.key'; BackupKey::generate($keyFile);
    $keyAlternate = $dir . '/wrong.key'; BackupKey::generate($keyAlternate);
    $vault = 'base64:' . base64_encode(random_bytes(32));
    $appKey = 'base64:' . base64_encode(random_bytes(32));
    foreach (['DB_CONNECTION'=>'pgsql', 'DB_HOST'=>$host, 'DB_PORT'=>$port,'DB_DATABASE'=>$source,
              'DB_USERNAME'=>$user,'DB_PASSWORD'=>$pass,'DB_SSLMODE'=>$sslmode,
              'APP_ENV'=>'local','APP_URL'=>'http://127.0.0.1:8080',
              'STORAGE_DISK'=>'local','STORAGE_LOCAL_ROOT'=>$storage,
              'VAULT_MASTER_KEY'=>$vault,'VAULT_MASTER_KEY_FILE'=>'','APP_KEY'=>$appKey] as $name => $value) {
        putenv($name . '=' . $value);
    }
    $app = (new Application($root))->boot();
    $service = new BackupService($app);
    $archive = $dir . '/encrypted.cjdr';
    $created = $service->create($archive, $keyFile);
    $assert($created['created'] && $created['summary']['database_bytes'] > 64, 'real pg_dump custom-format archive encrypted');
    $report = $service->verify($archive, $keyFile);
    $assert($report['verified'] && $report['summary']['recovery_files'] >= 2, 'full backup authenticates storage and recovery secrets');
    $reject(fn()=>$service->verify($archive,$keyAlternate), 'wrong disaster recovery key');
    $assert(!str_contains(file_get_contents($archive), 'Cajeer DR test payload'), 'database plaintext absent from encrypted backup');
    $assert(!str_contains(file_get_contents($archive), substr($blob,200,60)), 'storage plaintext absent from encrypted backup');
    $reject(fn()=>$service->restore($archive,$keyFile,$target,$target,$restoreStorage,null,true),
        'full restore without recovery-secret directory');
    $assert(!file_exists($restoreStorage), 'preflight failure did not create storage target');
    $result = $service->restore($archive,$keyFile,$target,$target,$restoreStorage,$restoreRecovery,true);
    $assert($result['restored'] === true, 'pg_restore --single-transaction committed encrypted backup');
    $targetPdo = new PDO($dsn($target), $user, $pass, $options);
    $assert((string)$targetPdo->query('SELECT message FROM "'.$schema.'".dr_records WHERE id=1')->fetchColumn() === 'Cajeer DR test payload',
        'actual PostgreSQL SQL/data restored');
    $assert(hash_file('sha256',$restoreStorage.'/nested/test.bin') === hash('sha256',$blob),'actual storage file restored byte-for-byte');
    $assert(trim(file_get_contents($restoreRecovery.'/vault.key')) === $vault,'Vault root key recoverable offline');
    $assert(trim(file_get_contents($restoreRecovery.'/app.key')) === $appKey,'APP_KEY recoverable offline');
    $assert((fileperms($restoreRecovery.'/vault.key') & 0777) === 0600,'recovery secrets stored as 0600');
    $reject(fn()=>$service->restore($archive,$keyFile,$target,$target,$dir.'/second-storage',$dir.'/second-recovery',true),
        'nonempty target PostgreSQL database protected from overwrite');
    $assert(!file_exists($dir.'/second-storage'),'rejected repeat restore does not create files');
    echo "PASS: $checks real PostgreSQL encrypted backup and offline DR integration assertions.\n";
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, 'FAIL backup-postgres-integration: ' . $error::class . ': ' . $error->getMessage() . "\n");
} finally {
    $targetPdo = null;
    if ($sourcePdo !== null && $createdSchema) {
        try { $sourcePdo->exec('DROP SCHEMA "'.$schema.'" CASCADE'); }
        catch (Throwable $error) { fwrite(STDERR, "FAIL: source schema cleanup.\n"); $failed = true; }
    }
    $sourcePdo = null;
    if ($adminPdo !== null && $createdDatabase) {
        try { $adminPdo->exec('DROP DATABASE "'.$target.'" WITH (FORCE)'); }
        catch (Throwable $error) { fwrite(STDERR, "FAIL: restore target cleanup.\n"); $failed = true; }
    }
    $adminPdo = null;
    $app?->terminate();
    foreach ($changed as $key => $value) {
        if ($value === false) putenv($key); else putenv($key . '=' . $value);
    }
    $delete($dir);
}
exit($failed ? 1 : 0);
