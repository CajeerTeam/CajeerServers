<?php
declare(strict_types=1);

namespace Cajeer\Servers\Queue;

interface QueueDriverInterface
{
    public function push(QueuedJob $job, int $delaySeconds = 0): string;
    public function reserve(string $queue = 'default', int $leaseSeconds = 60): ?QueueReservation;
    public function succeed(QueueReservation $reservation, array $result): void;
    public function release(QueueReservation $reservation, string $reason, int $delaySeconds): void;
    public function find(string $id): ?array;
    public function failed(int $limit = 50): array;
    public function retry(string $id): bool;
    public function prune(int $days = 7, string $queue = 'default', int $limit = 500): int;
}
