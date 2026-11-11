<?php
declare(strict_types=1);

namespace Cajeer\Servers\Backup;

use RuntimeException;

/** Versioned, length-delimited authenticated stream; no plaintext staging archive. */
final class EncryptedArchive
{
    private const MAGIC = "CJDR038\x00";
    public const CHUNK = 262144; // 256 KiB bounded memory on both sides.
    private const MAX_FRAME = self::CHUNK + 8192;

    /** @param resource $out */
    public static function writer($out, string $key): ArchiveWriter
    {
        self::writeAll($out, self::MAGIC);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        self::writeAll($out, $state[1]);
        return new ArchiveWriter($out, $state[0]);
    }

    /**
     * Stream-decrypt, authenticate and check checksums/ordering, with optional verified-event handlers.
     * Events: header, file_start, file_chunk, file_end, database_chunk, database_end, end.
     * Callbacks process ONLY a trusted archive supplied by the operator; authentication and hashes
     * finish at end. For destructive use, complete a verify-only pass first.
     * @param array<string, callable(mixed):void> $on
     * @return array<string,mixed>
     */
    public static function scan(string $archive, string $key, array $on = []): array
    {
        if (!is_file($archive) || is_link($archive) || !is_readable($archive)) throw new RuntimeException('Encrypted backup is not a readable regular file.');
        $in = @fopen($archive, 'rb');
        if (!is_resource($in)) throw new RuntimeException('Cannot open encrypted backup.');
        try {
            if (self::readExact($in, strlen(self::MAGIC)) !== self::MAGIC) throw new RuntimeException('Unsupported encrypted backup format.');
            $head = self::readExact($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($head, $key);
            $phase = 'start'; $current = null; $currentHash = null; $currentBytes = 0;
            $dbHash = hash_init('sha256'); $dbSize = 0; $files = 0; $storageBytes = 0; $recoveryFiles = 0;
            $seen = []; $metadata = []; $complete = false; $final = [];
            while (true) {
                $length = self::readExact($in, 4);
                $size = unpack('Nsize', $length)['size'];
                if ($size < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES + 1 || $size > self::MAX_FRAME) {
                    throw new RuntimeException('Encrypted backup frame length is invalid.');
                }
                $packet = self::readExact($in, $size);
                $unsealed = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $packet);
                if ($unsealed === false) throw new RuntimeException('Backup authentication failed (wrong key or corrupted ciphertext).');
                [$payload, $tag] = $unsealed;
                if ($payload === '') throw new RuntimeException('Empty backup record.');
                $type = $payload[0]; $data = substr($payload, 1);
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL && $type !== 'T') {
                    throw new RuntimeException('Unexpected encrypted stream final tag.');
                }
                if ($type === 'H' && $phase === 'start') {
                    $metadata = self::json($data);
                    if (($metadata['format'] ?? null) !== 1 || !in_array($metadata['mode'] ?? '', ['full', 'database'], true)
                        || !is_string($metadata['database'] ?? null) || !is_string($metadata['created_utc'] ?? null)) {
                        throw new RuntimeException('Invalid authenticated backup header.');
                    }
                    $phase = 'files'; self::emit($on, 'header', $metadata);
                } elseif ($type === 'F' && $phase === 'files') {
                    $current = self::json($data);
                    $name = $current['path'] ?? null;
                    if (!is_string($name) || !self::validPath($name) || isset($seen[$name])
                        || !is_int($current['size'] ?? null) || $current['size'] < 0 || $current['size'] > 1099511627776) {
                        throw new RuntimeException('Invalid or repeated backup file entry.');
                    }
                    if ($metadata['mode'] === 'database' || ++$files > 200000) throw new RuntimeException('Unexpected file entry in backup.');
                    $seen[$name] = true; $currentBytes = 0; $currentHash = hash_init('sha256'); $phase = 'file';
                    self::emit($on, 'file_start', $current);
                } elseif ($type === 'C' && $phase === 'file') {
                    if ($data === '' || strlen($data) > self::CHUNK) throw new RuntimeException('Invalid backup file chunk.');
                    $currentBytes += strlen($data);
                    if ($currentBytes > $current['size']) throw new RuntimeException('Backup file size limit exceeded.');
                    hash_update($currentHash, $data);
                    self::emit($on, 'file_chunk', $data);
                } elseif ($type === 'f' && $phase === 'file') {
                    $digest = hash_final($currentHash);
                    $check = self::json($data);
                    if (($check['sha256'] ?? '') !== $digest || $currentBytes !== $current['size'] || ($check['bytes'] ?? null) !== $currentBytes) {
                        throw new RuntimeException('Backup file checksum/size mismatch.');
                    }
                    if (str_starts_with($current['path'], 'storage/')) $storageBytes += $currentBytes;
                    else $recoveryFiles++;
                    self::emit($on, 'file_end', $current);
                    $current = null; $phase = 'files';
                } elseif ($type === 'D' && in_array($phase, ['files', 'db'], true)) {
                    if ($data === '' || strlen($data) > self::CHUNK) throw new RuntimeException('Invalid database stream chunk.');
                    $phase = 'db'; $dbSize += strlen($data); hash_update($dbHash, $data);
                    self::emit($on, 'database_chunk', $data);
                } elseif ($type === 'd' && $phase === 'db') {
                    $check = self::json($data); $digest = hash_final($dbHash);
                    if ($dbSize < 64 || ($check['sha256'] ?? '') !== $digest || ($check['bytes'] ?? null) !== $dbSize) {
                        throw new RuntimeException('Database dump checksum/size mismatch.');
                    }
                    $phase = 'end'; self::emit($on, 'database_end', ['bytes' => $dbSize, 'sha256' => $digest]);
                } elseif ($type === 'T' && $phase === 'end' && $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $final = self::json($data);
                    if (($final['files'] ?? null) !== $files || ($final['database_bytes'] ?? null) !== $dbSize
                        || ($final['storage_bytes'] ?? null) !== $storageBytes || ($final['recovery_files'] ?? null) !== $recoveryFiles) {
                        throw new RuntimeException('Backup terminal manifest does not match authenticated records.');
                    }
                    if (fread($in, 1) !== '') throw new RuntimeException('Trailing bytes after encrypted backup final tag.');
                    $complete = true; self::emit($on, 'end', $final);
                    break;
                } else {
                    throw new RuntimeException('Invalid backup record ordering or final tag.');
                }
                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) {
                    throw new RuntimeException('Unexpected encrypted backup message tag.');
                }
            }
            if (!$complete) throw new RuntimeException('Encrypted backup is incomplete.');
            return ['verified' => true, 'header' => $metadata, 'summary' => $final];
        } finally { fclose($in); }
    }

    public static function validPath(string $path): bool
    {
        if (strlen($path) > 768 || preg_match('/[\x00-\x1f\x7f\\\\]/', $path) || str_contains($path, '//')) return false;
        if (!str_starts_with($path, 'storage/') && !in_array($path, ['recovery/.env','recovery/vault.key','recovery/app.key'], true)) return false;
        foreach (explode('/', $path) as $segment) if ($segment === '' || $segment === '.' || $segment === '..') return false;
        return true;
    }

    private static function json(string $value): array
    {
        if (strlen($value) > 8192) throw new RuntimeException('Backup metadata is oversized.');
        $result = json_decode($value, true, 10, JSON_THROW_ON_ERROR);
        if (!is_array($result) || array_is_list($result)) throw new RuntimeException('Backup metadata must be an object.');
        return $result;
    }

    /** @param resource $in */
    private static function readExact($in, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $part = fread($in, $length - strlen($data));
            if (!is_string($part) || $part === '') throw new RuntimeException('Encrypted backup truncated.');
            $data .= $part;
        }
        return $data;
    }

    /** @param resource $out */
    public static function writeAll($out, string $data): void
    {
        $written = 0; $size = strlen($data);
        while ($written < $size) {
            $n = @fwrite($out, substr($data, $written));
            if (!is_int($n) || $n < 1) throw new RuntimeException('Encrypted backup stream write failed.');
            $written += $n;
        }
    }

    private static function emit(array $on, string $name, mixed $argument): void
    {
        if (isset($on[$name])) $on[$name]($argument);
    }
}

