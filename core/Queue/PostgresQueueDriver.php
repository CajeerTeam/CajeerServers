<?php
declare(strict_types=1);

namespace Cajeer\Servers\Queue;

use Cajeer\Servers\Database\DatabaseManager;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/** PostgreSQL leased queue: SKIP LOCKED claims, fenced acks, persistent result/failed history. */
final class PostgresQueueDriver implements QueueDriverInterface
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function push(QueuedJob $job, int $delaySeconds = 0): string
    {
        if ($delaySeconds < 0 || $delaySeconds > 2592000) throw new InvalidArgumentException('Invalid job delay.');
        $stmt = $this->pdo()->prepare("INSERT INTO queue_jobs (id,queue,type,payload,max_attempts,available_at) VALUES (:id,:queue,:type,CAST(:payload AS jsonb),:max_attempts,now()+(:delay * interval '1 second')) ON CONFLICT (id) DO NOTHING");
        $stmt->execute(['id'=>$job->id,'queue'=>$job->queue,'type'=>$job->type,'payload'=>json_encode($job->payload, JSON_THROW_ON_ERROR), 'max_attempts'=>$job->maxAttempts,'delay'=>$delaySeconds]);
        // An existing ID must not silently be used for a different job.
        if ($stmt->rowCount() === 0) {
            $existing = $this->find($job->id);
            if ($existing === null || $existing['type'] !== $job->type || $existing['queue'] !== $job->queue || $existing['payload'] !== $job->payload || $existing['max_attempts'] !== $job->maxAttempts) {
                throw new RuntimeException('Queue job ID conflicts with an existing different payload.');
            }
        }
        return $job->id;
    }

    public function reserve(string $queue = 'default', int $leaseSeconds = 60): ?QueueReservation
    {
        self::validateQueue($queue);
        if ($leaseSeconds < 5 || $leaseSeconds > 3600) throw new InvalidArgumentException('Invalid lease duration.');
        $receipt = bin2hex(random_bytes(24));
        return $this->database->transaction(function (PDO $pdo) use ($queue,$leaseSeconds,$receipt): ?QueueReservation {
            $pdo->exec("UPDATE queue_jobs SET status='failed',lease_token=NULL,reserved_until=NULL,finished_at=now(),updated_at=now(),last_error='Worker lease expired' WHERE queue=" . $pdo->quote($queue) . " AND status='running' AND reserved_until <= now() AND attempts >= max_attempts");
            $sql = <<<'SQL'
WITH candidate AS (
 SELECT id FROM queue_jobs
 WHERE queue=:queue AND attempts < max_attempts
 AND ((status='queued' AND available_at <= now()) OR (status='running' AND reserved_until <= now()))
 ORDER BY available_at, created_at, id
 FOR UPDATE SKIP LOCKED LIMIT 1
)
UPDATE queue_jobs j SET status='running',attempts=j.attempts+1,
 lease_token=:receipt,reserved_until=now()+(:lease * interval '1 second'),updated_at=now()
FROM candidate WHERE j.id=candidate.id
RETURNING j.id,j.type,j.payload,j.queue,j.max_attempts,j.attempts
SQL;
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['queue'=>$queue,'receipt'=>$receipt,'lease'=>$leaseSeconds]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) return null;
            return new QueueReservation(new QueuedJob((string)$row['id'],(string)$row['type'],self::jsonArray($row['payload']), (string)$row['queue'], (int)$row['max_attempts']),$receipt,(int)$row['attempts']);
        });
    }

    public function succeed(QueueReservation $reservation, array $result): void
    {
        $payload = json_encode($result, JSON_THROW_ON_ERROR);
        if (strlen($payload) > 65536) throw new InvalidArgumentException('Job result exceeds 64 KiB.');
        $stmt = $this->pdo()->prepare("UPDATE queue_jobs SET status='completed', result=CAST(:result AS jsonb), lease_token=NULL,reserved_until=NULL,finished_at=now(),updated_at=now() WHERE id=:id AND status='running' AND lease_token=:receipt AND reserved_until>now()");
        $stmt->execute(['id'=>$reservation->job->id,'receipt'=>$reservation->receipt,'result'=>$payload]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Stale queue job receipt; completion denied.');
    }

    public function release(QueueReservation $reservation, string $reason, int $delaySeconds): void
    {
        if ($delaySeconds < 0 || $delaySeconds > 2592000) throw new InvalidArgumentException('Invalid retry delay.');
        $stmt = $this->pdo()->prepare("UPDATE queue_jobs SET status=CASE WHEN attempts>=max_attempts THEN 'failed' ELSE 'queued' END, available_at=now()+(:delay * interval '1 second'), last_error=:reason, lease_token=NULL,reserved_until=NULL, finished_at=CASE WHEN attempts>=max_attempts THEN now() ELSE NULL END, updated_at=now() WHERE id=:id AND status='running' AND lease_token=:receipt AND reserved_until>now()");
        $stmt->execute(['delay'=>$delaySeconds,'reason'=>substr($reason,0,512),'id'=>$reservation->job->id,'receipt'=>$reservation->receipt]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Stale queue job receipt; release denied.');
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT id,queue,type,payload,status,attempts,max_attempts,last_error,result,created_at,finished_at FROM queue_jobs WHERE id=:id');
        $stmt->execute(['id'=>$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $row['payload'] = self::jsonArray($row['payload']);
        $row['result'] = $row['result'] === null ? null : self::jsonArray($row['result']);
        $row['attempts'] = (int)$row['attempts']; $row['max_attempts'] = (int)$row['max_attempts'];
        return $row;
    }

    public function failed(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 200) throw new InvalidArgumentException('Invalid failed jobs limit.');
        $stmt = $this->pdo()->prepare('SELECT id FROM queue_jobs WHERE status=\'failed\' ORDER BY finished_at DESC LIMIT :limit');
        $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
        $stmt->execute();
        return array_values(array_filter(array_map(fn ($row) => $this->find((string)$row['id']),$stmt->fetchAll(PDO::FETCH_ASSOC))));
    }

    public function retry(string $id): bool
    {
        $stmt = $this->pdo()->prepare("UPDATE queue_jobs SET status='queued',attempts=0,available_at=now(),last_error=NULL,finished_at=NULL,lease_token=NULL,reserved_until=NULL,updated_at=now() WHERE id=:id AND status='failed'");
        $stmt->execute(['id'=>$id]);
        return $stmt->rowCount() === 1;
    }

    public function prune(int $days = 7, string $queue = 'default', int $limit = 500): int
    {
        self::validateQueue($queue);
        if ($days < 1 || $days > 3650 || $limit < 1 || $limit > 2000) throw new InvalidArgumentException('Invalid prune range.');
        $stmt = $this->pdo()->prepare(<<<'SQL'
WITH expired AS (
 SELECT id FROM queue_jobs WHERE queue=:queue AND status IN ('failed','completed')
 AND finished_at < now()-(:days * interval '1 day') ORDER BY finished_at LIMIT :limit
)
DELETE FROM queue_jobs j USING expired WHERE j.id=expired.id
SQL);
        $stmt->bindValue(':queue',$queue);
        $stmt->bindValue(':days',$days,PDO::PARAM_INT);
        $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    private function pdo(): PDO
    {
        if ($this->database->driver() !== 'pgsql') throw new RuntimeException('Postgres queue requires PostgreSQL database.');
        return $this->database->connection();
    }

    private static function jsonArray(mixed $json): array
    {
        $v = is_array($json) ? $json : json_decode((string)$json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($v)) throw new RuntimeException('Invalid queue JSON record.');
        return $v;
    }

    public static function validateQueue(string $queue): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$queue)) throw new InvalidArgumentException('Invalid queue name.');
    }
}
