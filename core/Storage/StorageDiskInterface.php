<?php
declare(strict_types=1);

namespace Cajeer\Servers\Storage;

interface StorageDiskInterface
{
    public function put(string $key, string $contents): void;
    public function get(string $key): string;
    public function delete(string $key): bool;
    public function exists(string $key): bool;
}
