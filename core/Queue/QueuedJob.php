<?php
namespace Cajeer\Servers\Queue;

/** Queued infrastructure task payload. */
final class QueuedJob
{
    public function name(): string
    {
        return 'QueuedJob';
    }
}
