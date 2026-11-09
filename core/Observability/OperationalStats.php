<?php
declare(strict_types=1);

namespace Cajeer\Servers\Observability;

use Cajeer\Servers\Cache\RedisClient;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Queue\PostgresQueueDriver;
use PDO;
use RuntimeException;

/** Actual persisted queue/scheduler state, never arbitrary payloads or secrets. */
final class OperationalStats
{
    public function __construct(private readonly ConfigRepository $config,
        private readonly DatabaseManager $db, private readonly RedisClient $redis) {}

    public function queue(string $queue): array
    {
        PostgresQueueDriver::validateQueue($queue);
        $driver = (string)$this->config->get('queue.default', 'redis');
        if ($driver === 'redis') {
            $prefix = (string)$this->config->get('queue.redis_prefix', 'cajeer:queue:');
            $ready = $prefix . 'ready:' . $queue;
            $now = (string)time();
            return [
                'driver' => 'redis', 'queue' => $queue,
                'ready' => (int)$this->redis->command('ZCOUNT', $ready, '-inf', $now),
                'delayed' => (int)$this->redis->command('ZCOUNT', $ready, '(' . $now, '+inf'),
                'running' => (int)$this->redis->command('ZCARD', $prefix . 'processing:' . $queue),
                'completed' => (int)$this->redis->command('ZCARD', $prefix . 'completed:' . $queue),
                'failed' => (int)$this->redis->command('ZCARD', $prefix . 'failed:' . $queue),
            ];
        }
        if ($driver !== 'pgsql' || $this->db->driver() !== 'pgsql') throw new RuntimeException('Unsupported queue driver.');
        $stmt = $this->db->connection()->prepare('SELECT status, COUNT(*) AS total FROM queue_jobs WHERE queue=:queue GROUP BY status');
        $stmt->execute(['queue' => $queue]);
        $summary = ['driver' => 'pgsql', 'queue' => $queue, 'queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (array_key_exists((string)$row['status'], $summary)) $summary[(string)$row['status']] = (int)$row['total'];
        }
        return $summary;
    }

    public function scheduler(): array
    {
        if ($this->db->driver() !== 'pgsql') throw new RuntimeException('Scheduler requires PostgreSQL.');
        $pdo = $this->db->connection();
        $tasks = $pdo->query("SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE enabled) AS enabled, COUNT(*) FILTER (WHERE enabled AND next_run_at<=now()) AS due FROM scheduler_tasks")?->fetch(PDO::FETCH_ASSOC);
        $outbox = $pdo->query("SELECT COUNT(*) FILTER (WHERE status='pending') AS pending, COUNT(*) FILTER (WHERE status='sent') AS sent FROM scheduler_outbox")?->fetch(PDO::FETCH_ASSOC);
        if (!is_array($tasks) || !is_array($outbox)) throw new RuntimeException('Scheduler statistics unavailable.');
        return ['tasks' => array_map('intval', $tasks), 'outbox' => array_map('intval', $outbox)];
    }

    /** Bounded retention cleanup; audit_log intentionally untouched. */
    public function pruneSecurity(int $days, int $limit, bool $apply): array
    {
        if ($days < 7 || $days > 3650 || $limit < 1 || $limit > 5000) throw new \InvalidArgumentException('Invalid retention window or limit.');
        if ($this->db->driver() !== 'pgsql') throw new RuntimeException('Security cleanup requires PostgreSQL.');
        $tables = [
            'auth_login_attempts' => 'window_started',
            'security_rate_limits' => 'expires_at',
            'auth_sessions' => 'expires_at',
            'webhook_events' => 'received_at',
        ];
        $result = [];
        return $this->db->transaction(function (PDO $pdo) use ($tables, $days, $limit, $apply, &$result): array {
            foreach ($tables as $table => $column) {
                // SQL identifiers are fixed internal literals, never user controlled.
                $sql = $apply
                    ? "DELETE FROM $table WHERE ctid IN (SELECT ctid FROM $table WHERE $column < now() - (CAST(:days AS integer) * interval '1 day') LIMIT $limit FOR UPDATE SKIP LOCKED)"
                    : "SELECT count(*) FROM (SELECT 1 FROM $table WHERE $column < now() - (CAST(:days AS integer) * interval '1 day') LIMIT $limit) AS candidates";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['days' => $days]);
                $result[$table] = $apply ? $stmt->rowCount() : (int)$stmt->fetchColumn();
            }
            return ['applied' => $apply, 'days' => $days, 'limit_per_table' => $limit, 'rows' => $result,
                'audit_log_unchanged' => true];
        });
    }
}
