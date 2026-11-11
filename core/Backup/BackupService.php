<?php
declare(strict_types=1);

namespace Cajeer\Servers\Backup;

use Cajeer\Servers\Config\EnvironmentLoader;
use Cajeer\Servers\Credentials\VaultMasterKey;
use Cajeer\Servers\Kernel\Application;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/** Operational backup and offline DR of PostgreSQL, local storage and essential runtime secrets. */
final class BackupService
{
    public function __construct(private readonly Application $app) {}

    public function create(string $output, string $keyFile, bool $databaseOnly = false): array
    {
        $key = BackupKey::load($keyFile);
        try {
            $targetDir = $this->newParent($output);
            if (file_exists($output) || is_link($output)) throw new RuntimeException('Backup target already exists; refusing overwrite.');
            if ($this->inside($output, $this->app->basePath('public'))) {
                throw new RuntimeException('Encrypted backup must never be stored under the public web root.');
            }
            $storageRoot = null; $files = []; $secrets = [];
            if (!$databaseOnly) {
                $storageRoot = $this->storageRoot();
                $files = $storageRoot === null ? [] : $this->listFiles($storageRoot);
                if ($storageRoot !== null && $this->inside($keyFile, $storageRoot)) {
                    throw new RuntimeException('The backup decryption key must not reside inside backed-up storage.');
                }
                if ($storageRoot !== null && $this->inside($output, $storageRoot)) {
                    throw new RuntimeException('Backup destination cannot be inside the backed-up storage tree.');
                }
                $env = $this->app->basePath('.env');
                if (is_link($env)) throw new RuntimeException('Refusing to back up symlinked .env file.');
                if (is_file($env)) {
                    $secrets['recovery/.env'] = $this->readSmall($env, 1048576);
                }
                $vaultFile = EnvironmentLoader::getString('VAULT_MASTER_KEY_FILE');
                $vaultInline = EnvironmentLoader::getString('VAULT_MASTER_KEY');
                if ($vaultFile !== '' || $vaultInline !== '') {
                    $raw = (new VaultMasterKey())->load();
                    try {
                        if (hash_equals($key, $raw)) throw new RuntimeException('Backup key and Vault key must be distinct.');
                        $secrets['recovery/vault.key'] = 'base64:' . base64_encode($raw) . "\n";
                    }
                    finally { sodium_memzero($raw); }
                }
                $appKey = EnvironmentLoader::getString('APP_KEY');
                if ($appKey !== '') {
                    $decodedApp = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
                    if (is_string($decodedApp) && hash_equals($key, $decodedApp)) {
                        throw new RuntimeException('Backup key and APP_KEY must be distinct.');
                    }
                    $secrets['recovery/app.key'] = $appKey . "\n";
                }
            }
            $db = new PostgresStream($this->app->config());
            $database = $db->sourceDatabase();
            if ($keyFile === $output || $this->inside($output, dirname($keyFile) . '/' . basename($keyFile))) {
                throw new RuntimeException('Backup file cannot overwrite the backup decryption key.');
            }
            $temp = tempnam($targetDir, '.cajeer-encrypted-');
            if ($temp === false) throw new RuntimeException('Cannot allocate encrypted backup output.');
            $handle = null;
            try {
                if (!@chmod($temp, 0600)) throw new RuntimeException('Cannot secure encrypted backup output.');
                $handle = @fopen($temp, 'wb');
                if (!is_resource($handle)) throw new RuntimeException('Cannot write encrypted backup.');
                $writer = EncryptedArchive::writer($handle, $key);
                $writer->json('H', [
                    'format' => 1, 'mode' => $databaseOnly ? 'database' : 'full',
                    'created_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                    'database' => $database, 'app_version' => $this->app->version(),
                    'consistent_storage' => false, // DB and files require operator-enforced quiescence.
                ]);
                $count = 0; $storageBytes = 0;
                foreach ($files as $relative => $path) {
                    $storageBytes += $this->writeFile($writer, 'storage/' . $relative, $path);
                    $count++;
                }
                foreach ($secrets as $name => $content) {
                    $this->writeMemory($writer, $name, $content);
                    $count++;
                }
                $db->startDump();
                try {
                    $dump = $db->readDump(static fn (string $chunk) => $writer->emit('D', $chunk));
                } catch (Throwable $error) { $db->abort(); throw $error; }
                $writer->json('d', $dump);
                $summary = [
                    'files' => $count, 'recovery_files' => count($secrets),
                    'storage_bytes' => $storageBytes, 'database_bytes' => $dump['bytes'],
                ];
                $writer->json('T', $summary, true);
                if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                    throw new RuntimeException('Could not durably flush encrypted backup.');
                }
                fclose($handle); $handle = null;
                // Authenticate what was actually written, before publication.
                EncryptedArchive::scan($temp, $key);
                // Exclusive hardlink creation cannot overwrite a competing backup target.
                if (file_exists($output) || is_link($output) || !@link($temp, $output)) {
                    throw new RuntimeException('Cannot safely publish new encrypted backup without overwriting existing files.');
                }
                @unlink($temp);
                return ['created' => true, 'archive' => $output, 'mode' => $databaseOnly ? 'database' : 'full',
                    'database' => $database, 'summary' => $summary, 'key_id' => BackupKey::identifier($key)];
            } finally {
                $db->abort();
                if (is_resource($handle)) fclose($handle);
                if (is_file($temp)) @unlink($temp);
            }
        } finally { sodium_memzero($key); }
    }

    public function verify(string $archive, string $keyFile): array
    {
        $key = BackupKey::load($keyFile);
        try {
            $report = EncryptedArchive::scan($archive, $key);
            $report['key_id'] = BackupKey::identifier($key);
            return $report;
        } finally { sodium_memzero($key); }
    }

    /**
     * Restore ONLY to an empty target PostgreSQL database, and new storage/secrets directories.
     * Offline operator must stop web, queue, scheduler and connector workers before invoking.
     */
    public function restore(string $archive, string $keyFile, string $targetDatabase, string $confirmation,
        ?string $storageTarget, ?string $recoveryTarget, bool $offline): array
    {
        if (!$offline || $targetDatabase === '' || $confirmation !== $targetDatabase) {
            throw new RuntimeException('Restoration requires --offline and --confirm-target=<exact target database name>.');
        }
        $checked = $this->verify($archive, $keyFile); // Full authentication before any mutations.
        $mode = $checked['header']['mode'];
        $summary = $checked['summary'];
        if ($mode === 'full' && ($storageTarget === null || $storageTarget === '')) {
            throw new RuntimeException('Full recovery requires --storage-target=/new/empty/path.');
        }
        if (($summary['recovery_files'] ?? 0) > 0 && ($recoveryTarget === null || $recoveryTarget === '')) {
            throw new RuntimeException('Encrypted recovery secrets require --recovery-target=/new/private/path.');
        }
        if ($mode === 'database' && ($storageTarget !== null || $recoveryTarget !== null)) {
            throw new RuntimeException('Database-only archive has no local storage or recovery secrets.');
        }
        $paths = array_filter(['storage' => $storageTarget, 'recovery' => $recoveryTarget], static fn ($x) => $x !== null);
        foreach ($paths as $name => $path) {
            $this->newParent($path);
            if (file_exists($path) || is_link($path)) throw new RuntimeException('Restore target directory already exists: ' . $name);
            if ($this->inside($path, $this->app->basePath('public'))) {
                throw new RuntimeException('Restore directories must not be under the public web root.');
            }
            if ($path === $archive || $path === $keyFile || $this->inside($archive, $path) || $this->inside($keyFile, $path)) {
                throw new RuntimeException('Restore target overlaps archive or decryption key.');
            }
        }
        if ($storageTarget !== null && $recoveryTarget !== null && ($storageTarget === $recoveryTarget
            || $this->inside($storageTarget, $recoveryTarget) || $this->inside($recoveryTarget, $storageTarget))) {
            throw new RuntimeException('Restore storage and recovery directories must be separate.');
        }
        $db = new PostgresStream($this->app->config());
        $db->checkEmptyTarget($targetDatabase);
        $stages = []; $published = []; $active = null; $opened = false; $completed = false;
        $key = BackupKey::load($keyFile);
        try {
            foreach ($paths as $name => $target) {
                $stage = dirname($target) . '/.cajeer-stage-' . bin2hex(random_bytes(12));
                if (!@mkdir($stage, 0700) || !@chmod($stage, 0700)) throw new RuntimeException('Cannot create private restoration staging directory.');
                $stages[$name] = $stage;
            }
            $callbacks = [
                'file_start' => function (array $entry) use ($stages, &$active): void {
                    $name = $entry['path'];
                    $kind = str_starts_with($name, 'storage/') ? 'storage' : 'recovery';
                    if (!isset($stages[$kind])) throw new RuntimeException('Missing restore staging directory.');
                    $relative = substr($name, strlen($kind) + 1);
                    $path = $stages[$kind] . '/' . $relative;
                    $directory = dirname($path);
                    if (!is_dir($directory) && !@mkdir($directory, 0700, true)) throw new RuntimeException('Restore file directory creation failed.');
                    $fd = @fopen($path, 'xb');
                    if (!is_resource($fd)) throw new RuntimeException('Cannot exclusively create restored file.');
                    if (!@chmod($path, 0600)) { fclose($fd); throw new RuntimeException('Cannot restrict restored file permissions.'); }
                    $active = $fd;
                },
                'file_chunk' => function (string $chunk) use (&$active): void {
                    if (!is_resource($active)) throw new RuntimeException('Restoration file is not open.');
                    EncryptedArchive::writeAll($active, $chunk);
                },
                'file_end' => function () use (&$active): void {
                    if (!is_resource($active) || !fflush($active) || (function_exists('fsync') && !fsync($active))) {
                        throw new RuntimeException('Restoration staging file flush failed.');
                    }
                    fclose($active); $active = null;
                },
                'database_chunk' => function (string $chunk) use ($db, $targetDatabase, &$opened, &$stages, &$published, $paths): void {
                    if (!$opened) {
                        // Publish immutable staged files BEFORE database transaction begins.
                        foreach ($stages as $kind => $path) {
                            if (file_exists($paths[$kind]) || is_link($paths[$kind]) || !@rename($path, $paths[$kind])) {
                                throw new RuntimeException('Cannot safely publish restored storage or secrets.');
                            }
                            $published[$kind] = $paths[$kind]; unset($stages[$kind]);
                        }
                        $db->startRestore($targetDatabase); $opened = true;
                    }
                    $db->writeRestore($chunk);
                },
            ];
            EncryptedArchive::scan($archive, $key, $callbacks);
            if (!$opened) throw new RuntimeException('Backup has no database stream.');
            $db->finish();
            $completed = true;
            return ['restored' => true, 'target_database' => $targetDatabase,
                'storage_target' => $storageTarget, 'recovery_target' => $recoveryTarget, 'summary' => $summary,
                'warning' => 'Offline restore completed. Review recovery secrets and update .env paths before restarting services.'];
        } finally {
            $db->abort();
            if (is_resource($active)) fclose($active);
            // Once pg_restore was started, a broken client/connection may leave the
            // commit outcome uncertain. Preserve published recovery files for manual
            // inspection, rather than deleting data that might already be committed.
            if (!$completed && !$opened) {
                foreach ($published as $path) $this->removeTree($path);
            }
            if (!$completed) {
                foreach ($stages as $path) $this->removeTree($path);
            }
            sodium_memzero($key);
        }
    }

    /** @return array<string,string> */
    private function listFiles(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $node) {
            if ($node->isLink() || (!$node->isFile() && !$node->isDir())) {
                throw new RuntimeException('Unsupported symlink or special file in storage; aborting backup.');
            }
            if (!$node->isFile()) continue;
            $path = $node->getPathname();
            $relative = substr($path, strlen($root) + 1);
            if (!EncryptedArchive::validPath('storage/' . $relative) || !is_readable($path)) {
                throw new RuntimeException('Unsafe storage filename or unreadable file.');
            }
            $files[$relative] = $path;
            if (count($files) > 200000) throw new RuntimeException('Storage contains too many files.');
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private function writeFile(ArchiveWriter $writer, string $name, string $path): int
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000 || $before['size'] > 1099511627776) {
            throw new RuntimeException('Storage file became unsafe during backup.');
        }
        $fd = @fopen($path, 'rb');
        if (!is_resource($fd)) throw new RuntimeException('Cannot open storage file for backup.');
        try {
            $opened = fstat($fd);
            if (!is_array($opened) || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']) {
                throw new RuntimeException('Storage file changed during opening.');
            }
            $writer->json('F', ['path' => $name, 'size' => $before['size']]);
            $hash = hash_init('sha256'); $bytes = 0;
            while (!feof($fd)) {
                $chunk = @fread($fd, EncryptedArchive::CHUNK);
                if ($chunk === false) throw new RuntimeException('Storage file read failed.');
                if ($chunk === '') continue;
                $bytes += strlen($chunk);
                if ($bytes > $before['size']) throw new RuntimeException('Storage file grew during backup.');
                hash_update($hash, $chunk); $writer->emit('C', $chunk);
            }
            clearstatcache(true, $path);
            $after = @lstat($path);
            if (!is_array($after) || $bytes !== $before['size'] || $after['size'] !== $before['size']
                || $after['mtime'] !== $before['mtime'] || $after['ino'] !== $before['ino']) {
                throw new RuntimeException('Storage file changed during backup; quiesce writers.');
            }
            $writer->json('f', ['sha256' => hash_final($hash), 'bytes' => $bytes]);
            return $bytes;
        } finally { fclose($fd); }
    }

    private function writeMemory(ArchiveWriter $writer, string $name, string $content): void
    {
        $writer->json('F', ['path' => $name, 'size' => strlen($content)]);
        foreach (str_split($content, EncryptedArchive::CHUNK) as $chunk) {
            if ($chunk !== '') $writer->emit('C', $chunk);
        }
        $writer->json('f', ['bytes' => strlen($content), 'sha256' => hash('sha256', $content)]);
    }

    private function storageRoot(): ?string
    {
        if ($this->app->config()->get('storage.default') !== 'local') {
            throw new RuntimeException('Full recovery supports local storage only; use --db-only and a separate verified S3 recovery plan.');
        }
        $configured = (string) $this->app->config()->get('storage.local.root', '');
        $root = $configured !== '' ? $configured : $this->app->basePath('storage/files');
        if ($root[0] !== '/' || is_link($root)) throw new RuntimeException('Storage root must be a non-symlink absolute directory.');
        if (!file_exists($root)) return null;
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved) || !is_readable($resolved)) throw new RuntimeException('Local storage root is unavailable.');
        return $resolved;
    }

    private function readSmall(string $path, int $limit): string
    {
        $size = filesize($path);
        if ($size === false || $size > $limit) throw new RuntimeException('Recovery configuration file is too large.');
        $data = @file_get_contents($path);
        if (!is_string($data) || strlen($data) > $limit) throw new RuntimeException('Cannot read recovery configuration.');
        return $data;
    }

    /** Requires exact canonical parent to reject symlink ancestors and dot-path ambiguity. */
    private function newParent(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, '//')
            || in_array(basename($path), ['', '.', '..'], true)) throw new RuntimeException('An absolute canonical output path is required.');
        $parent = dirname($path);
        if (realpath($parent) !== $parent || !is_dir($parent) || !is_writable($parent)) {
            throw new RuntimeException('Output parent must be an existing writable canonical directory without symlinks.');
        }
        return $parent;
    }

    private function inside(string $child, string $parent): bool
    {
        return $child === $parent || str_starts_with($child, rtrim($parent, '/') . '/');
    }

    /** Delete only newly-created staging trees, never contents of a pre-existing destination. */
    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) return;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
