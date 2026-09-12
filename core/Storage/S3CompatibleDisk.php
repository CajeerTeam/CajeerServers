<?php
namespace Cajeer\Servers\Storage;

/** S3-compatible storage disk. */
final class S3CompatibleDisk
{
    public function name(): string
    {
        return 'S3CompatibleDisk';
    }
}
