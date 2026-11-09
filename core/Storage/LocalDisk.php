<?php
declare(strict_types=1);

namespace Cajeer\Servers\Storage;

use RuntimeException;

/** File storage outside the web root; refuses symlinks and writes atomically. */
final class LocalDisk implements StorageDiskInterface
{
    private string $root;

    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot initialize local storage.');
        }
        $root = realpath($directory);
        if ($root === false || is_link($directory) || !is_writable($root)) throw new RuntimeException('Unsafe local storage directory.');
        $this->root = $root;
    }

    public function put(string $key, string $contents): void
    {
        if (strlen($contents) > 16 * 1024 * 1024) throw new RuntimeException('Object exceeds 16 MiB storage limit.');
        $path = $this->path($key, true);
        $dir = dirname($path);
        $tmp = tempnam($dir, '.cajeer-');
        if ($tmp === false) throw new RuntimeException('Cannot allocate storage temporary file.');
        try {
            if (!chmod($tmp, 0600) || file_put_contents($tmp, $contents, LOCK_EX) !== strlen($contents)) throw new RuntimeException('Storage write failed.');
            // Cross-process writers are safely serialized by atomic rename; never follow target symlinks.
            if (is_link($path) || (file_exists($path) && !is_file($path))) throw new RuntimeException('Unsafe storage destination.');
            if (!@rename($tmp, $path)) throw new RuntimeException('Atomic storage rename failed.');
        } finally { if (is_file($tmp)) @unlink($tmp); }
    }

    public function get(string $key): string
    {
        $path = $this->path($key);
        if (is_link($path) || !is_file($path)) throw new RuntimeException('Storage object not found.');
        $size = filesize($path);
        if ($size === false || $size > 16 * 1024 * 1024) throw new RuntimeException('Storage object exceeds size limit.');
        $value = @file_get_contents($path);
        if ($value === false) throw new RuntimeException('Storage read failed.');
        return $value;
    }

    public function exists(string $key): bool
    {
        $path = $this->path($key);
        return !is_link($path) && is_file($path);
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key);
        if (is_link($path)) throw new RuntimeException('Unsafe storage object.');
        if (!file_exists($path)) return false;
        if (!is_file($path) || !@unlink($path)) throw new RuntimeException('Storage deletion failed.');
        return true;
    }

    private function path(string $key, bool $create = false): string
    {
        StorageKey::validate($key);
        $segments = explode('/', $key);
        $name = array_pop($segments);
        $dir = $this->root;
        foreach ($segments as $segment) {
            $dir .= '/' . $segment;
            if (is_link($dir)) throw new RuntimeException('Symlink in storage path.');
            if (!is_dir($dir) && $create && !@mkdir($dir, 0700) && !is_dir($dir)) throw new RuntimeException('Cannot create storage folder.');
            if (file_exists($dir) && !is_dir($dir)) throw new RuntimeException('Storage path is not a directory.');
        }
        return $dir . '/' . $name;
    }
}
