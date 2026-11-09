<?php
declare(strict_types=1);

namespace Cajeer\Servers\Queue;

final class QueueReservation
{
    public function __construct(
        public readonly QueuedJob $job,
        public readonly string $receipt,
        public readonly int $attempt,
    ) {}
}
