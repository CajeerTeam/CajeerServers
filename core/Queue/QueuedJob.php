<?php
declare(strict_types=1);

namespace Cajeer\Servers\Queue;

use InvalidArgumentException;

/** Immutable, JSON-serializable, registered-handler job descriptor. */
final class QueuedJob
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $payload = [],
        public readonly string $queue = 'default',
        public readonly int $maxAttempts = 3,
    ) {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $id)
            || !preg_match('/^[a-z][a-z0-9._-]{0,79}$/D', $type)
            || !preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $queue)
            || $maxAttempts < 1 || $maxAttempts > 25 || array_is_list($payload) && $payload !== []) {
            throw new InvalidArgumentException('Invalid queue job descriptor.');
        }
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 65536) throw new InvalidArgumentException('Queue job payload exceeds 64 KiB.');
    }

    public static function create(string $type, array $payload = [], string $queue = 'default', int $maxAttempts = 3): self
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $s = bin2hex($b);
        return new self(substr($s,0,8).'-'.substr($s,8,4).'-'.substr($s,12,4).'-'.substr($s,16,4).'-'.substr($s,20), $type, $payload, $queue, $maxAttempts);
    }

    public function data(): array
    {
        return ['id'=>$this->id,'type'=>$this->type,'payload'=>$this->payload,'queue'=>$this->queue,'max_attempts'=>$this->maxAttempts];
    }
}
