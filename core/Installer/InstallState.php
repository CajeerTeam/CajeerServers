<?php
declare(strict_types=1);

namespace Cajeer\Servers\Installer;

use RuntimeException;

/** Filesystem install lock and atomic local install marker. DB is the second authority. */
final class InstallState
{
    public function __construct(private readonly string $root) {}

    public function isInstalled(): bool
    {
        return is_file($this->directory() . '/installed.json');
    }

    /** @template T @param callable():T $callback @return T */
    public function exclusive(callable $callback): mixed
    {
        $path = $this->directory() . '/setup.lock';
        if (is_link($path)) throw new RuntimeException('Unsafe installer lock path.');
        $handle = @fopen($path, 'c+');
        if ($handle === false) throw new RuntimeException('Cannot create installer lock.');
        @chmod($path, 0600);
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Cannot obtain installer lock.');
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array{installed: bool, installed_at: ?string} */
    public function status(): array
    {
        $data = $this->record();
        return ['installed' => $data !== null, 'installed_at' => $data['installed_at'] ?? null];
    }

    public function record(): ?array
    {
        $path = $this->directory() . '/installed.json';
        if (is_link($path)) throw new RuntimeException('Unsafe installation marker.');
        if (!file_exists($path)) return null;
        $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_string($data['installed_at'] ?? null)
            || !is_string($data['installation_id'] ?? null) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $data['installation_id'])) {
            throw new RuntimeException('Invalid installation marker.');
        }
        return $data;
    }

    public function finish(string $installationId, string $installedAt): void
    {
        $this->atomic('installed.json', json_encode([
            'installation_id' => $installationId,
            'installed_at' => $installedAt,
            'version' => trim((string) @file_get_contents($this->root . '/VERSION')) ?: 'unknown',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        $token = $this->directory() . '/web-install-token.sha256';
        if (is_file($token) && !is_link($token)) @unlink($token);
    }

    public function issueWebToken(): string
    {
        return $this->exclusive(function (): string {
            if ($this->isInstalled()) throw new RuntimeException('CajeerServers is already installed.');
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $this->atomic('web-install-token.sha256', hash('sha256', $token) . "\n");
            return $token;
        });
    }

    public function hasWebToken(): bool
    {
        $file = $this->directory() . '/web-install-token.sha256';
        return is_file($file) && !is_link($file);
    }

    public function verifyWebToken(string $token): bool
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) return false;
        $file = $this->directory() . '/web-install-token.sha256';
        if (!is_file($file) || is_link($file)) return false;
        $digest = trim((string) file_get_contents($file));
        return preg_match('/^[a-f0-9]{64}$/D', $digest) === 1 && hash_equals($digest, hash('sha256', $token));
    }

    public function directory(): string
    {
        $base = realpath($this->root);
        if ($base === false) throw new RuntimeException('Invalid project root.');
        $path = $base . '/storage/app';
        if (is_link($path)) throw new RuntimeException('Installer state must not be symlinked.');
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create private installer state directory.');
        }
        if (!is_writable($path)) throw new RuntimeException('Installer state directory is not writable.');
        return $path;
    }

    private function atomic(string $name, string $data): void
    {
        $path = $this->directory() . '/' . $name;
        if (is_link($path)) throw new RuntimeException('Unsafe installer state file.');
        $temporary = tempnam($this->directory(), '.cajeer-');
        if ($temporary === false) throw new RuntimeException('Unable to write installer state.');
        try {
            $handle = fopen($temporary, 'wb');
            if (!is_resource($handle)) throw new RuntimeException('Cannot open temporary state file.');
            try {
                if (!chmod($temporary, 0600) || fwrite($handle,$data)!==strlen($data) || !fflush($handle) || !fsync($handle)) throw new RuntimeException('Cannot durably write state file.');
            } finally { fclose($handle); }
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Unable to atomically write installer state.');
            }
            $this->syncDirectory(dirname($path));
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }
    }
    private function syncDirectory(string $directory): void
    {
        if (PHP_OS_FAMILY !== 'Linux') return;
        $handle=fopen($directory,'r');
        if (!is_resource($handle)) throw new RuntimeException('Cannot open state directory for durability.');
        try { if (!fsync($handle)) throw new RuntimeException('Cannot durably sync state directory.'); }
        finally { fclose($handle); }
    }
}
