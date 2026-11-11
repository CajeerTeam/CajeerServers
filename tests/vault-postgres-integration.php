#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Actual PostgreSQL 17+ integration. Operates only in random disposable schema in cajeer_test_* DB. */
require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Credentials\CredentialsVault;
use Cajeer\Servers\Credentials\VaultCrypto;
use Cajeer\Servers\Credentials\VaultMasterKey;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;

if (getenv('CAJEER_PG_TEST') !== '1' || !preg_match('/^cajeer_test_[a-z0-9_]+$/D', getenv('CAJEER_PG_TEST_DATABASE') ?: '')) {
    fwrite(STDERR, "REFUSED: requires CAJEER_PG_TEST=1 and disposable CAJEER_PG_TEST_DATABASE=cajeer_test_*\n");
    exit(2);
}
if (!extension_loaded('pdo_pgsql') || !extension_loaded('sodium')) {
    fwrite(STDERR, "FAIL: PostgreSQL and libsodium PHP extensions are required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . '/cajeer-vault-pg-' . bin2hex(random_bytes(8));
$schema = 'cajeer_vault_' . bin2hex(random_bytes(8));
mkdir($directory . '/migrations', 0700, true);
$pdo = null; $created = false; $checks = 0; $error = null;
$assert = static function (bool $valid, string $message) use (&$checks): void {
    if (!$valid) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
};
$reject = static function (callable $action, string $message) use ($assert): void {
    try { $action(); } catch (Throwable) { $assert(true, $message); return; }
    throw new RuntimeException('FAIL: ' . $message . ' was not rejected');
};
try {
    $db = new DatabaseManager(new ConfigRepository($root . '/config'), new ConnectionFactory(), [
        'driver' => 'pgsql', 'host' => getenv('CAJEER_PG_TEST_HOST') ?: '127.0.0.1',
        'port' => getenv('CAJEER_PG_TEST_PORT') ?: '5432',
        'database' => getenv('CAJEER_PG_TEST_DATABASE'),
        'username' => getenv('CAJEER_PG_TEST_USERNAME') ?: 'cajeer',
        'password' => getenv('CAJEER_PG_TEST_PASSWORD') ?: '',
        'sslmode' => getenv('CAJEER_PG_TEST_SSLMODE') ?: 'prefer',
    ]);
    $pdo = $db->connection();
    $assert((int) $pdo->query("SELECT current_setting('server_version_num')")->fetchColumn() >= 170000, 'PostgreSQL 17+');
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public');
    $pdo->exec('CREATE SCHEMA "' . $schema . '"');
    $created = true;
    $pdo->exec('SET search_path TO "' . $schema . '", public');
    foreach (glob($root . '/migrations/pgsql/*.sql') ?: [] as $file) {
        if (!copy($file, $directory . '/migrations/' . basename($file))) throw new RuntimeException('Cannot prepare migrations.');
    }
    $runner = new MigrationRunner($db, $directory . '/migrations');
    $assert(count($runner->run()['applied']) === 17, 'all 17 migrations applied');
    $assert($runner->validate()['valid'] === true, 'migration checksums valid');
    $assert($runner->run()['applied'] === [], 'migration replay idempotent');

    $secret = random_bytes(32);
    $key = new VaultMasterKey('base64:' . base64_encode(random_bytes(32)));
    $crypto = new VaultCrypto($key);
    $vault = new CredentialsVault($db, $crypto, $key);
    $assert($vault->initialize()['initialized'] === true, 'first-time enrollment');
    $assert($vault->check()['ok'] === true, 'registered key decrypts verifier');
    $reject(fn() => $vault->initialize(), 're-enrollment refused');
    $wrong = new VaultMasterKey('base64:' . base64_encode(random_bytes(32)));
    $reject(fn() => (new CredentialsVault($db, new VaultCrypto($wrong), $wrong))->check(), 'wrong master key fails');
    $assert($vault->probe()['ok'] === true, 'actual PostgreSQL encrypted insert/read/decrypt');
    $assert((int)$pdo->query("SELECT count(*) FROM credentials WHERE type='vault_probe'")->fetchColumn() === 0, 'probe rolls back ephemeral data');

    $id = 'c2111111-bbbb-4ddd-8abc-111111111111';
    $workspace = 'd2222222-bbbb-4ccc-8abc-222222222222';
    $cipher = $crypto->seal($secret, $id, $workspace, 'api_token');
    $stmt = $pdo->prepare("INSERT INTO credentials(id,name,type,encrypted_payload,metadata) VALUES(CAST(:id AS uuid),'Vault integration','api_token',decode(:payload,'hex'),'{}'::jsonb)");
    $stmt->execute(['id'=>$id, 'payload'=>bin2hex($cipher)]);
    $select = $pdo->prepare("SELECT encode(encrypted_payload,'hex') FROM credentials WHERE id=CAST(:id AS uuid)");
    $select->execute(['id'=>$id]);
    $dbCipher = hex2bin((string)$select->fetchColumn());
    $assert(is_string($dbCipher) && $dbCipher === $cipher, 'ciphertext survives database persistence');
    $reloadedCrypto = new VaultCrypto(new VaultMasterKey('base64:' . base64_encode($key->load())));
    $assert($reloadedCrypto->open($dbCipher, $id, $workspace, 'api_token') === $secret, 'new crypto instance restores persisted secret');
    $assert(!str_contains($dbCipher, $secret), 'raw secret not in DB ciphertext');
    $reject(fn() => $reloadedCrypto->open($dbCipher, $id, 'd2222222-bbbb-4ccc-8abc-222222222223', 'api_token'), 'wrong workspace fails');
    $reject(fn() => $reloadedCrypto->open($dbCipher, $id, $workspace, 'ssh_password'), 'wrong type fails');
    $reject(fn() => $pdo->exec("UPDATE vault_key_verifiers SET key_id=repeat('0',32) WHERE id=1"), 'verifier UPDATE rejected');
    $reject(fn() => $pdo->exec('DELETE FROM vault_key_verifiers WHERE id=1'), 'verifier DELETE rejected');
    $reject(fn() => $pdo->exec('TRUNCATE vault_key_verifiers'), 'verifier TRUNCATE rejected');
    $assert($vault->check()['ok'] === true, 'verifier remains valid after blocked writes');
    echo "Vault PostgreSQL integration: {$checks} PASS\n";
} catch (Throwable $e) {
    $error = $e;
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
} finally {
    if ($pdo instanceof PDO && $created) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); $pdo->exec('RESET search_path'); $pdo->exec('DROP SCHEMA "' . $schema . '" CASCADE'); }
        catch (Throwable $cleanup) { fwrite(STDERR, "FAIL: isolated schema cleanup failed.\n"); $error ??= $cleanup; }
    }
    foreach (glob($directory . '/migrations/*.sql') ?: [] as $file) @unlink($file);
    @rmdir($directory . '/migrations'); @rmdir($directory);
}
exit($error === null ? 0 : 1);
