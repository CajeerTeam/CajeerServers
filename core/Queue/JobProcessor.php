<?php
declare(strict_types=1);

namespace Cajeer\Servers\Queue;

use Cajeer\Servers\Runtime\RuntimeDiagnostics;
use Cajeer\Servers\Storage\StorageDiskInterface;
use Cajeer\Servers\Inventory\TransferService;
use RuntimeException;

/** Allowlisted application jobs; never runs raw commands, eval or user-supplied PHP. */
final class JobProcessor
{
    public function __construct(private readonly RuntimeDiagnostics $diagnostics, private readonly StorageDiskInterface $storage, private readonly QueueDriverInterface $queue, private readonly ?TransferService $transfer = null) {}

    public function supports(string $type): bool
    {
        return in_array($type, ['runtime.diagnostics','storage.probe','queue.prune','inventory.import'],true);
    }

    public function process(QueuedJob $job): array
    {
        return match ($job->type) {
            'runtime.diagnostics' => $this->runtime(),
            'storage.probe' => $this->storageProbe(),
            'queue.prune' => $this->prune($job->payload),
            'inventory.import' => $this->import($job->payload),
            default => throw new RuntimeException('No registered handler for job type.'),
        };
    }

    private function import(array $payload): array
    {
        if ($this->transfer === null || array_keys($payload) !== ['batch_id'] || !is_string($payload['batch_id']))
            throw new RuntimeException('Invalid import queue job.');
        return $this->transfer->processBatch($payload['batch_id']);
    }

    private function prune(array $payload): array
    {
        $days = $payload['days'] ?? 7;
        $queue = $payload['queue'] ?? 'default';
        if (!is_int($days) || !is_string($queue)) throw new RuntimeException('Invalid queue pruning payload.');
        return ['deleted'=>$this->queue->prune($days, $queue)];
    }

    private function runtime(): array
    {
        $diagnostics = $this->diagnostics->run();
        if (!$diagnostics['ok']) throw new RuntimeException('Runtime diagnostic checks failed.');
        return $diagnostics;
    }

    private function storageProbe(): array
    {
        $key = '_health/' . bin2hex(random_bytes(12));
        $bytes = random_bytes(128);
        $this->storage->put($key,$bytes);
        try {
            if (!$this->storage->exists($key) || !hash_equals($bytes,$this->storage->get($key))) {
                throw new RuntimeException('Storage probe read-back validation failed.');
            }
        } finally { $this->storage->delete($key); }
        return ['ok'=>true,'operation'=>'storage.probe'];
    }
}
