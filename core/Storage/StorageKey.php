<?php
declare(strict_types=1);

namespace Cajeer\Servers\Storage;

use InvalidArgumentException;

final class StorageKey
{
    public static function validate(string $key): string
    {
        if ($key === '' || strlen($key) > 512 || str_starts_with($key, '/') || str_contains($key, "\\") || str_contains($key, "\0")) {
            throw new InvalidArgumentException('Unsafe storage key.');
        }
        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 128 || preg_match('/[\x00-\x1F\x7F]/', $segment)) {
                throw new InvalidArgumentException('Unsafe storage path segment.');
            }
        }
        return $key;
    }
}
