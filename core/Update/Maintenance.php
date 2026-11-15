<?php
declare(strict_types=1);

namespace Cajeer\Servers\Update;

use RuntimeException;
use Cajeer\Servers\Installer\InstallState;

/** Single-host admission control. Never unlink the lock inode while processes may hold it. */
final class Maintenance
{
    private string $directory;
    public function __construct(private readonly string $root) { $this->directory = (new InstallState($root))->directory(); }

    public function requiresUpgrade(): bool
    {
        $marker=(new InstallState($this->root))->record();
        if ($marker===null) return false;
        $version=trim((string)file_get_contents($this->root.'/VERSION'));
        return ($marker['version'] ?? null)!==$version;
    }

    public function active(): bool
    {
        clearstatcache(true, $this->directory . '/maintenance.json');
        if (file_exists($this->directory . '/maintenance.json') || is_link($this->directory . '/maintenance.json')) return true;
        $journal=$this->journal();
        return $journal!==null && $journal['phase']!=='complete';
    }

    public function shared(): mixed
    {
        $lock = $this->lock(LOCK_SH, 0);
        try {
            if ($this->active() || $this->requiresUpgrade()) throw new RuntimeException('Maintenance or release upgrade required.');
            return $lock;
        } catch (\Throwable $e) { fclose($lock); throw $e; }
    }

    public function exclusive(callable $operation, int $seconds = 0): mixed
    {
        $lock = $this->lock(LOCK_EX, $seconds);
        try { return $operation(); } finally { fclose($lock); }
    }

    private function lock(int $mode, int $seconds): mixed
    {
        $path = $this->directory . '/maintenance.lock';
        if (is_link($path)) throw new RuntimeException('Unsafe maintenance lock.');
        $mask = umask(0077);
        try { $handle = @fopen($path, 'c+b'); } finally { umask($mask); }
        if (!is_resource($handle)) throw new RuntimeException('Cannot open maintenance lock.');
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        do {
            if (flock($handle, $mode | LOCK_NB)) return $handle;
            if (hrtime(true) >= $deadline) break;
            usleep(50_000);
        } while (true);
        fclose($handle);
        throw new RuntimeException('Maintenance lock busy; drain HTTP requests and stop workers before retrying.');
    }

    /** Called only inside exclusive(). */
    public function enable(): void
    {
        if (!file_exists($this->directory . '/maintenance.json')) $this->write('maintenance.json', ['started_at'=>gmdate(DATE_ATOM)]);
    }

    public function disable(): void
    {
        if (($journal=$this->journal()) !== null && $journal['phase'] !== 'complete') throw new RuntimeException('Incomplete upgrade; resume or restore offline before reopening.');
        $path = $this->directory . '/maintenance.json';
        if (is_link($path) || (is_file($path) && !unlink($path))) throw new RuntimeException('Cannot clear maintenance marker.');
        $this->syncDirectory();
    }

    public function journal(): ?array
    {
        $path = $this->directory . '/upgrade.json';
        clearstatcache(true,$path);
        if (is_link($path)) throw new RuntimeException('Unsafe upgrade journal.');
        if (!file_exists($path)) return null;
        $raw = file_get_contents($path, false, null, 0, 65537);
        if ($raw === false || strlen($raw) > 65536) throw new RuntimeException('Unreadable upgrade journal.');
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !in_array($data['phase'] ?? null, ['backup','migrate','verify','complete'], true)) throw new RuntimeException('Invalid upgrade journal.');
        return $data;
    }

    public function save(array $data): void { $this->write('upgrade.json', $data); }

    private function write(string $name, array $data): void
    {
        $path = $this->directory . '/' . $name;
        if (is_link($path)) throw new RuntimeException('Unsafe maintenance state.');
        $tmp = tempnam($this->directory, '.upgrade-');
        if ($tmp === false) throw new RuntimeException('Cannot create maintenance state.');
        $handle = null;
        try {
            if (!chmod($tmp, 0600)) throw new RuntimeException('Cannot secure maintenance state.');
            $handle = fopen($tmp, 'wb');
            $bytes = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            if (!$handle || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) throw new RuntimeException('Cannot persist maintenance state.');
            fclose($handle); $handle = null;
            if (!rename($tmp, $path)) throw new RuntimeException('Cannot publish maintenance state.');
            $this->syncDirectory();
        } finally { if (is_resource($handle)) fclose($handle); if (is_file($tmp)) unlink($tmp); }
    }
    private function syncDirectory(): void
    {
        if (PHP_OS_FAMILY!=='Linux') return;
        $handle=fopen($this->directory,'r');
        if (!is_resource($handle)) throw new RuntimeException('Cannot open maintenance directory.');
        try { if (!fsync($handle)) throw new RuntimeException('Cannot durably sync maintenance directory.'); }
        finally { fclose($handle); }
    }
}
