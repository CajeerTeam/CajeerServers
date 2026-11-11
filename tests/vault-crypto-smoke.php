#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Credentials\VaultCrypto;
use Cajeer\Servers\Credentials\VaultMasterKey;
use Cajeer\Servers\Credentials\CredentialsVault;
use Cajeer\Servers\Database\SqlStatementParser;
use Cajeer\Servers\Kernel\Application;

$checks = 0;
$ok = static function (bool $v, string $msg) use (&$checks): void {
    if (!$v) throw new RuntimeException('FAIL: ' . $msg);
    $checks++;
};
$reject = static function (callable $test, string $msg) use ($ok): void {
    try { $test(); } catch (Throwable) { $ok(true, $msg); return; }
    throw new RuntimeException('FAIL (not rejected): ' . $msg);
};
$key = random_bytes(32);
$root = new VaultMasterKey('base64:' . base64_encode($key));
$crypto = new VaultCrypto($root);
$id = 'a1211111-bbbb-4ddd-8abc-111111111111';
$ws = 'e2222222-bbbb-4ccc-8abc-222222222222';
$type = 'ssh_private_key';
$ok(strlen($root->keyId()) === 32, 'key id is 128 bit hexadecimal');
$ok($root->keyId() === VaultMasterKey::identifier($key), 'key identifier is deterministic');
foreach (['', "\0\xFFsome\nsecret", random_bytes(16384), str_repeat('Z', 1_048_576)] as $plaintext) {
    $a = $crypto->seal($plaintext, $id, $ws, $type);
    $b = $crypto->seal($plaintext, $id, $ws, $type);
    $ok($a !== $b, 'envelopes use random nonces and DEKs');
    $ok(!str_contains($a, $plaintext === '' ? 'This-can-never-be-in-a-binary-cipher' : $plaintext), 'plaintext is not embedded');
    $ok($crypto->open($a, $id, $ws, $type) === $plaintext, 'roundtrip authenticates and preserves bytes');
    $ok($crypto->open($b, strtoupper($id), strtoupper($ws), $type) === $plaintext, 'canonical UUID context');
    foreach ([0, 4, 19, 20, 43, 44, 90, strlen($a)-1] as $offset) {
        if ($offset >= strlen($a)) continue;
        $bad = $a;
        $bad[$offset] = chr(ord($bad[$offset]) ^ 1);
        $reject(fn () => $crypto->open($bad, $id, $ws, $type), 'tampering envelope is rejected at position ' . $offset);
    }
    $reject(fn () => $crypto->open($a, 'a1211111-bbbb-4ddd-8abc-111111111112', $ws, $type), 'credential UUID bound to ciphertext');
    $reject(fn () => $crypto->open($a, $id, 'e2222222-bbbb-4ccc-8abc-222222222223', $type), 'workspace bound to ciphertext');
    $reject(fn () => $crypto->open($a, $id, $ws, 'api_token'), 'type bound to ciphertext');
    $reject(fn () => (new VaultCrypto(new VaultMasterKey('base64:' . base64_encode(random_bytes(32)))))->open($a, $id, $ws, $type), 'wrong master key fails');
    $reject(fn () => $crypto->open(substr($a, 0, -1), $id, $ws, $type), 'truncated ciphertext rejected');
}
$reject(fn () => $crypto->seal('x', 'not-a-uuid', $ws, $type), 'invalid credential identifier');
$reject(fn () => $crypto->seal('x', $id, $ws, 'BAD_TYPE'), 'invalid credential type');
$reject(fn () => $crypto->seal(str_repeat('x', 1_048_577), $id, $ws, $type), 'oversized secret rejected');
$reject(fn () => (new VaultMasterKey())->load(), 'unconfigured key cannot be loaded');
$reject(fn () => (new VaultMasterKey('base64:broken!'))->load(), 'bad base64 refused');
$reject(fn () => (new VaultMasterKey('base64:' . base64_encode('short')))->load(), 'short key refused');
$reject(fn () => (new VaultMasterKey('base64:' . base64_encode(str_repeat("\0",32))))->load(), 'all zero key refused');
$reject(fn () => $crypto->open('malformed', $id, $ws, $type), 'unknown envelope version rejected');

$tmp = sys_get_temp_dir() . '/cajeer-vault-unit-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
try {
    $path = $tmp . '/vault.key';
    VaultMasterKey::generatePrivateFile($path);
    $ok(is_file($path), 'generated key exists');
    $ok((fileperms($path) & 0777) === 0600, 'generated key is mode 0600');
    $reject(fn() => VaultMasterKey::generatePrivateFile($path), 'cannot overwrite key');
    $old = getenv('VAULT_MASTER_KEY_FILE'); $oldEnv = getenv('VAULT_MASTER_KEY');
    putenv('VAULT_MASTER_KEY_FILE=' . $path); putenv('VAULT_MASTER_KEY');
    try {
        $ok(strlen((new VaultMasterKey())->load()) === 32, 'private file key loaded');
        chmod($path, 0644);
        $reject(fn() => (new VaultMasterKey())->load(), 'world-readable key rejected');
        chmod($path, 0600);
        symlink($path, $tmp.'/symlink');
        putenv('VAULT_MASTER_KEY_FILE=' . $tmp.'/symlink');
        $reject(fn() => (new VaultMasterKey())->load(), 'key symlink rejected');
        putenv('VAULT_MASTER_KEY_FILE=' . $path);
        putenv('VAULT_MASTER_KEY=base64:AAAA');
        $reject(fn() => (new VaultMasterKey())->load(), 'ambiguous key sources rejected');
    } finally {
        if ($old === false) putenv('VAULT_MASTER_KEY_FILE'); else putenv('VAULT_MASTER_KEY_FILE='.$old);
        if ($oldEnv === false) putenv('VAULT_MASTER_KEY'); else putenv('VAULT_MASTER_KEY='.$oldEnv);
    }
    $parser = new SqlStatementParser();
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/pgsql/0017_vault_key_verifier.sql');
    $ok(count($parser->split($sql)) === 4, 'new SQL migration parses into 4 statements');
    $oldEnvApp = getenv('APP_ENV'); putenv('APP_ENV=local');
    $app = (new Application(dirname(__DIR__)))->boot();
    try {
        $service = $app->container()->get(CredentialsVault::class);
        $ok($service instanceof CredentialsVault, 'registered Vault service resolved');
        $reject(fn()=>$service->check(), 'DB check cannot falsely pass when PostgreSQL is unavailable');
    } finally { $app->terminate(); if ($oldEnvApp === false) putenv('APP_ENV'); else putenv('APP_ENV='.$oldEnvApp); }
} finally {
    @unlink($tmp . '/symlink'); @unlink($tmp . '/vault.key'); @rmdir($tmp);
}
echo "Vault crypto smoke: {$checks} PASS\n";
