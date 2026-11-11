<?php
declare(strict_types=1);

namespace Cajeer\Servers\Credentials;

use Cajeer\Servers\Config\EnvironmentLoader;
use RuntimeException;

/** Isolated Vault root key; never falls back to APP_KEY or the legacy VAULT_KEY. */
final class VaultMasterKey
{
    public function __construct(private readonly ?string $testEncodedKey = null) {}

    public function load(): string
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('Vault requires ext-sodium.');
        }
        $encoded = $this->testEncodedKey;
        if ($encoded === null) {
            $envKey = EnvironmentLoader::getString('VAULT_MASTER_KEY');
            $file = EnvironmentLoader::getString('VAULT_MASTER_KEY_FILE');
            if ($envKey !== '' && $file !== '') {
                throw new RuntimeException('Configure only one Vault key source.');
            }
            if ($file !== '') {
                $encoded = self::readPrivateKeyFile($file);
            } else {
                $encoded = $envKey;
            }
            $appKey = EnvironmentLoader::getString('APP_KEY');
            if ($appKey !== '' && hash_equals($appKey, $encoded)) {
                throw new RuntimeException('Vault master key must be independent of APP_KEY.');
            }
        }
        if (!str_starts_with($encoded, 'base64:')) {
            throw new RuntimeException('Vault key must use base64: encoding.');
        }
        $key = base64_decode(substr($encoded, 7), true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('Vault master key must contain exactly 32 random bytes.');
        }
        if (hash_equals(str_repeat("\0", 32), $key)) {
            sodium_memzero($key);
            throw new RuntimeException('Invalid Vault master key.');
        }
        return $key;
    }

    public function keyId(): string
    {
        $key = $this->load();
        try {
            return self::identifier($key);
        } finally {
            sodium_memzero($key);
        }
    }

    public static function identifier(string $key): string
    {
        if (strlen($key) !== 32) throw new RuntimeException('Invalid Vault key material.');
        return bin2hex(substr(hash_hmac('sha256', 'cajeerservers:vault:key-id:v1', $key, true), 0, 16));
    }

    private static function readPrivateKeyFile(string $path): string
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || is_link($path) || !is_file($path)) {
            throw new RuntimeException('Vault key file must be an existing regular absolute path, not a symlink.');
        }
        clearstatcache(true, $path);
        $stat = @stat($path);
        if (!is_array($stat) || ($stat['mode'] & 0077) !== 0 || ($stat['size'] ?? 0) > 256) {
            throw new RuntimeException('Vault key file must have private permissions (0600) and valid size.');
        }
        if (function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid()) {
            throw new RuntimeException('Vault key file must be owned by the current service user.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) throw new RuntimeException('Vault key file is not readable.');
        try {
            $opened = fstat($stream);
            if (!is_array($opened) || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino'] || ($opened['mode'] & 0077) !== 0) {
                throw new RuntimeException('Vault key file changed during opening.');
            }
            $value = stream_get_contents($stream, 257);
            if (!is_string($value) || strlen($value) > 256) throw new RuntimeException('Vault key file is invalid.');
            return rtrim($value, "\r\n");
        } finally {
            fclose($stream);
        }
    }

    /** Creates a key file exclusively; never writes a raw key to stdout or logs. */
    public static function generatePrivateFile(string $path): void
    {
        if (!extension_loaded('sodium') || $path === '' || !str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new RuntimeException('A private absolute key file path and ext-sodium are required.');
        }
        $parent = dirname($path);
        if (!is_dir($parent) || is_link($parent) || !is_writable($parent)) {
            throw new RuntimeException('Vault key parent directory must exist and be writable.');
        }
        $key = random_bytes(32);
        $serialized = 'base64:' . base64_encode($key) . "\n";
        sodium_memzero($key);
        $umask = umask(0077);
        try {
            $fp = @fopen($path, 'x');
            if ($fp === false) throw new RuntimeException('Vault key file already exists or cannot be created.');
            try {
                if (!chmod($path, 0600) || fwrite($fp, $serialized) !== strlen($serialized) || !fflush($fp)) {
                    throw new RuntimeException('Unable to store Vault key securely.');
                }
            } catch (\Throwable $e) {
                @unlink($path);
                throw $e;
            } finally {
                fclose($fp);
            }
        } finally {
            umask($umask);
            sodium_memzero($serialized);
        }
    }
}
