#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Backup\BackupKey;
use Cajeer\Servers\Backup\BackupService;
use Cajeer\Servers\Backup\EncryptedArchive;
use Cajeer\Servers\Kernel\Application;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
};
$reject = static function (callable $callback, string $message) use ($assert): void {
    try { $callback(); } catch (Throwable) { $assert(true, $message); return; }
    throw new RuntimeException('FAIL: operation should have failed: ' . $message);
};
$base = sys_get_temp_dir() . '/cajeer-backup-unit-' . bin2hex(random_bytes(8));
if (!mkdir($base, 0700)) throw new RuntimeException('Cannot create private test directory.');
$delete = static function (string $path) use (&$delete): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') $delete($path . '/' . $child);
    }
    @rmdir($path);
};
$originals = [];
foreach (['APP_ENV', 'APP_URL', 'APP_KEY', 'VAULT_MASTER_KEY', 'VAULT_MASTER_KEY_FILE', 'STORAGE_DISK','STORAGE_LOCAL_ROOT','PG_DUMP_BINARY','BACKUP_KEY_FILE'] as $name) {
    $originals[$name] = getenv($name);
}
try {
    $keyfile = $base . '/recovery.key';
    BackupKey::generate($keyfile);
    $assert((fileperms($keyfile) & 0777) === 0600, 'key generated with restrictive permissions');
    $key = BackupKey::load($keyfile);
    $assert(strlen($key) === 32 && strlen(BackupKey::identifier($key)) === 24, 'private backup key and deterministic ID');
    $reject(fn() => BackupKey::generate($keyfile), 'existing key not overwritten');
    chmod($keyfile, 0644);
    $reject(fn() => BackupKey::load($keyfile), 'world-readable backup key blocked');
    chmod($keyfile, 0600);
    symlink($keyfile, $base . '/key-link');
    $reject(fn() => BackupKey::load($base . '/key-link'), 'symlink backup key rejected');

    $archive = $base . '/roundtrip.cjdr';
    $out = fopen($archive, 'wb');
    $w = EncryptedArchive::writer($out, $key);
    $w->json('H', ['format' => 1,'mode' => 'full','database' => 'testdb','created_utc' => '2026-11-11T00:00:00Z']);
    $content = random_bytes(EncryptedArchive::CHUNK + 771);
    $w->json('F', ['path' => 'storage/test.bin', 'size' => strlen($content)]);
    foreach (str_split($content, EncryptedArchive::CHUNK) as $block) $w->emit('C', $block);
    $w->json('f', ['bytes' => strlen($content), 'sha256' => hash('sha256', $content)]);
    $w->json('F', ['path' => 'recovery/.env','size' => 0]);
    $w->json('f', ['bytes' => 0, 'sha256' => hash('sha256', '')]);
    $dbBytes = 'PGDMP' . random_bytes(500000);
    foreach (str_split($dbBytes, EncryptedArchive::CHUNK) as $block) $w->emit('D', $block);
    $w->json('d', ['bytes' => strlen($dbBytes),'sha256' => hash('sha256',$dbBytes)]);
    $w->json('T', ['files' => 2,'storage_bytes' => strlen($content),'recovery_files' => 1,'database_bytes' => strlen($dbBytes)],true);
    fclose($out);
    $seen = ''; $database = ''; $found = [];
    $report = EncryptedArchive::scan($archive, $key, [
        'file_start' => static function (array $meta) use (&$found): void { $found[] = $meta['path']; },
        'file_chunk' => static function (string $chunk) use (&$seen): void { $seen .= $chunk; },
        'database_chunk' => static function (string $chunk) use (&$database): void { $database .= $chunk; },
    ]);
    $assert($report['verified'] && $report['summary']['files'] === 2, 'authenticated stream and manifest');
    $assert($seen === $content && $database === $dbBytes, 'binary streaming roundtrip across blocks');
    $assert($found === ['storage/test.bin','recovery/.env'], 'typed source paths');
    $assert(!str_contains(file_get_contents($archive), substr($dbBytes, 100, 40)), 'database is never stored as plaintext');
    $reject(fn() => EncryptedArchive::scan($archive, random_bytes(32)), 'wrong backup key rejected');
    $bad = $base . '/tampered.cjdr';
    $blob = file_get_contents($archive); $blob[100] = chr(ord($blob[100]) ^ 1);
    file_put_contents($bad, $blob);
    $reject(fn() => EncryptedArchive::scan($bad, $key), 'ciphertext tamper rejected');
    file_put_contents($bad, substr(file_get_contents($archive), 0, -31));
    $reject(fn() => EncryptedArchive::scan($bad, $key), 'missing authenticated final record rejected');
    file_put_contents($bad, file_get_contents($archive) . 'unauthenticated-extra');
    $reject(fn() => EncryptedArchive::scan($bad, $key), 'trailing unsigned data rejected');
    $assert(!EncryptedArchive::validPath('storage/../.env') && !EncryptedArchive::validPath('storage/../../etc/passwd'), 'path traversal prohibited');
    $assert(!EncryptedArchive::validPath('storage/evil\\file') && !EncryptedArchive::validPath('/tmp/evil'), 'absolute and backslash names prohibited');

    // Exercise real proc_open + stdout encryption without claiming a live PostgreSQL test.
    $root = $base . '/storage'; mkdir($root, 0700);
    mkdir($root . '/nested', 0700);
    file_put_contents($root . '/nested/asset.json', "token=dummy-test-secret\n");
    $binary = $base . '/fake-pg-dump';
    file_put_contents($binary, '#!' . PHP_BINARY . "\n<?php fwrite(STDOUT, 'PGDMP' . str_repeat('X', 400000));\n");
    chmod($binary, 0700);
    putenv('APP_ENV=local'); putenv('APP_URL=http://127.0.0.1:8080');
    putenv('APP_KEY=base64:' . base64_encode(random_bytes(32)));
    putenv('VAULT_MASTER_KEY'); putenv('VAULT_MASTER_KEY_FILE');
    putenv('STORAGE_DISK=local'); putenv('STORAGE_LOCAL_ROOT=' . $root);
    putenv('PG_DUMP_BINARY=' . $binary); putenv('BACKUP_KEY_FILE=' . $keyfile);
    $app = (new Application(dirname(__DIR__)))->boot();
    try {
        $service = new BackupService($app);
        $result = $service->create($base . '/from-process.cjdr', $keyfile);
        $assert($result['created'] && $result['summary']['files'] >= 2, 'real process stdout encrypted along with storage and app secret');
        $confirmed = $service->verify($base . '/from-process.cjdr',$keyfile);
        $assert($confirmed['verified'] && $confirmed['summary']['database_bytes'] === 400005, 'process backup checksum verified');
        $reject(fn() => $service->create($base . '/from-process.cjdr', $keyfile), 'existing backup not overwritten');
        $reject(fn() => $service->restore($base . '/from-process.cjdr', $keyfile, 'new_database', '', null, null, true), 'restore requires explicit confirmation');
        $reject(fn() => $service->restore($base . '/from-process.cjdr', $keyfile, 'new_database', 'new_database', null, null, true), 'full restore requires target directories');
        symlink($base . '/elsewhere', $root . '/nested/bad-link');
        $reject(fn() => $service->create($base . '/symlink-rejected.cjdr', $keyfile), 'storage symlink rejected');
        $assert(!is_file($base . '/symlink-rejected.cjdr'), 'failed backup not published');
        unlink($root . '/nested/bad-link');
        $badDump = $base . '/broken-pg-dump';
        file_put_contents($badDump, '#!' . PHP_BINARY . "\n<?php fwrite(STDOUT, 'PGDMPBROKEN'); exit(3);\n");
        chmod($badDump, 0700);
        putenv('PG_DUMP_BINARY=' . $badDump);
        $reject(fn() => $service->create($base . '/process-failure.cjdr', $keyfile), 'pg_dump non-zero exit rejects backup');
        $assert(!is_file($base . '/process-failure.cjdr'), 'failed dump leaves no published archive');
    } finally { $app->terminate(); }
} finally {
    foreach ($originals as $name => $value) {
        if ($value === false) putenv($name); else putenv($name . '=' . $value);
    }
    $delete($base);
}
echo "Encrypted backup native smoke: {$checks} PASS. Live PostgreSQL recovery is separately tested.\n";
