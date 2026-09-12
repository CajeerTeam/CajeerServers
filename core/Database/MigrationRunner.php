<?php
namespace Cajeer\Servers\Database;

final class MigrationRunner
{
    public function pending(string $path): array
    {
        return glob(rtrim($path, '/') . '/*.sql') ?: [];
    }
}
