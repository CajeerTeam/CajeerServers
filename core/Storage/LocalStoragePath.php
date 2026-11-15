<?php
declare(strict_types=1);

namespace Cajeer\Servers\Storage;

use RuntimeException;

/** Resolve existing ancestors before accepting a data directory, including an uncreated target. */
final class LocalStoragePath
{
    public static function resolve(string $applicationRoot, string $configured = ''): string
    {
        $root = realpath($applicationRoot);
        if ($root === false || !is_dir($root)) throw new RuntimeException('Invalid application root.');
        $path = $configured !== '' ? $configured : $root . '/storage/files';
        $target = self::canonical($path);
        $public = self::canonical($root . '/public');
        $default = self::canonical($root . '/storage/files');
        // A configured external storage root must not contain the application or
        // its document root. Inside the application only the dedicated data tree
        // is writable by storage operations, never config, code, logs or keys.
        if (self::within($root, $target) || self::within($target, $public) || self::within($public, $target)
            || (self::within($target, $root) && !self::within($target, $default))) {
            throw new RuntimeException('Local storage must be isolated from application code and the web root.');
        }
        return $target;
    }

    private static function canonical(string $path): string
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '//')) {
            throw new RuntimeException('STORAGE_LOCAL_ROOT must be an absolute canonical filesystem path.');
        }
        $path = rtrim($path, '/') ?: '/';
        foreach (explode('/', $path) as $part) {
            if ($part === '.' || $part === '..') throw new RuntimeException('Storage path cannot contain dot segments.');
        }
        if (is_link($path)) throw new RuntimeException('Storage root must not be a symbolic link.');
        $tail = [];
        $cursor = $path;
        while (($resolved = realpath($cursor)) === false) {
            if (is_link($cursor) || file_exists($cursor) || $cursor === '/') throw new RuntimeException('Invalid storage path ancestor.');
            array_unshift($tail, basename($cursor));
            $cursor = dirname($cursor);
        }
        if (!is_dir($resolved)) throw new RuntimeException('Storage path ancestor must be a directory.');
        return $tail === [] ? $resolved : rtrim($resolved, '/') . '/' . implode('/', $tail);
    }

    private static function within(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with($path, rtrim($parent, '/') . '/');
    }
}
