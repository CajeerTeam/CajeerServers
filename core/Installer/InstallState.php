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
        if (!$this->isInstalled()) return ['installed' => false, 'installed_at' => null];
        $path = $this->directory() . '/installed.json';
        if (is_link($path)) throw new RuntimeException('Unsafe installation marker.');
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['installed_at']) || !is_string($data['installed_at'])) {
            throw new RuntimeException('Invalid installation marker.');
        }
        return ['installed' => true, 'installed_at' => $data['installed_at']];
    }

    public function finish(string $installationId, string $installedAt): void
    {
        $this->atomic('installed.json', json_encode([
            'installation_id' => $installationId,
            'installed_at' => $installedAt,
            'version' => '0.1.4',
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
            if (!chmod($temporary, 0600) || file_put_contents($temporary, $data, LOCK_EX) !== strlen($data) || !rename($temporary, $path)) {
                throw new RuntimeException('Unable to atomically write installer state.');
            }
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }
    }
}
