<?php
declare(strict_types=1);

namespace Cajeer\Servers\Backup;

use RuntimeException;

/** A dedicated offline backup key. Not derived from APP_KEY or the Vault master key. */
final class BackupKey
{
    public static function generate(string $path): void
    {
        $parent = self::parent($path, true);
        if (file_exists($path) || is_link($path)) throw new RuntimeException('Backup key target already exists.');
        $key = random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
        $content = 'base64:' . base64_encode($key) . "\n";
        sodium_memzero($key);
        $old = umask(0077);
        try { $out = @fopen($path, 'x+b'); } finally { umask($old); }
        if (!is_resource($out)) throw new RuntimeException('Cannot exclusively create backup key file.');
        try {
            if (!@chmod($path, 0600) || fwrite($out, $content) !== strlen($content) || !fflush($out)) {
                throw new RuntimeException('Cannot save backup key file.');
            }
        } catch (\Throwable $error) {
            fclose($out); @unlink($path);
            throw $error;
        }
        fclose($out);
    }

    public static function load(string $path): string
    {
        self::parent($path);
        if (is_link($path) || !is_file($path)) throw new RuntimeException('Backup key must be a regular file, not a symlink.');
        clearstatcache(true, $path);
        $before = @stat($path);
        if (!is_array($before) || ($before['mode'] & 0077) !== 0 || $before['size'] > 256 || $before['size'] < 43) {
            throw new RuntimeException('Backup key requires private permissions and 32-byte key material.');
        }
        if (function_exists('posix_geteuid') && $before['uid'] !== posix_geteuid()) {
            throw new RuntimeException('Backup key must be owned by the CLI user.');
        }
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) throw new RuntimeException('Backup key cannot be opened.');
        try {
            $opened = fstat($stream);
            if (!is_array($opened) || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev'] || ($opened['mode'] & 0077) !== 0) {
                throw new RuntimeException('Backup key changed while opening.');
            }
            $encoded = trim((string) stream_get_contents($stream, 257));
        } finally { fclose($stream); }
        if (!str_starts_with($encoded, 'base64:')) throw new RuntimeException('Backup key must be base64 encoded.');
        $decoded = base64_decode(substr($encoded, 7), true);
        if (!is_string($decoded) || strlen($decoded) !== 32 || hash_equals($decoded, str_repeat("\0", 32))) {
            throw new RuntimeException('Invalid backup key material.');
        }
        return $decoded;
    }

    public static function identifier(string $key): string
    {
        return bin2hex(substr(hash_hmac('sha256', 'cajeer:disaster-recovery:key:v1', $key, true), 0, 12));
    }

    /** Canonical, existing parent; final component must be a simple filename. */
    public static function parent(string $path, bool $mustBeWritable = false): string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || in_array(basename($path), ['', '.', '..'], true)) {
            throw new RuntimeException('An absolute file path is required.');
        }
        $parent = dirname($path);
        if (is_link($parent) || ($resolved = realpath($parent)) === false || !is_dir($resolved) || ($mustBeWritable && !is_writable($resolved))) {
            throw new RuntimeException('Private file parent must exist and be writable.');
        }
        return $resolved;
    }
}
