<?php
declare(strict_types=1);

namespace Cajeer\Servers\Scheduler;

use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Queue\JobProcessor;
use Cajeer\Servers\Queue\QueuedJob;
use Cajeer\Servers\Queue\QueueDriverInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** PostgreSQL task scheduler and transactional outbox for Redis/Postgres queue dispatch. */
final class Scheduler
{
    public function __construct(private readonly DatabaseManager $db, private readonly QueueDriverInterface $queue, private readonly JobProcessor $handlers, private readonly ?\Cajeer\Servers\Connector\ConnectorExecutionService $connectors=null) {}

    public function add(string $name, string $cron, string $timezone, string $jobType, array $payload = [], string $queue = 'default', bool $runNow = false): array
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{0,79}$/D',$name)) throw new InvalidArgumentException('Invalid scheduled task name.');
        if ($queue==='connectors') throw new InvalidArgumentException('Use connector collection plans for the reserved queue.');
        if (!$this->handlers->supports($jobType)) throw new InvalidArgumentException('Unregistered scheduled job handler.');
        $job=QueuedJob::create($jobType,$payload,$queue);
        $schedule=new ScheduledTask($cron,$timezone);
        $now=new DateTimeImmutable((string)$this->pdo()->query('SELECT clock_timestamp()')->fetchColumn());
        $first=$schedule->next($now);
        $next=$runNow ? $now : $first;
        $stmt=$this->pdo()->prepare('INSERT INTO scheduler_tasks (name,cron_expression,timezone,job_type,payload,queue,next_run_at) VALUES (:name,:cron,:timezone,:type,CAST(:payload AS jsonb),:queue,:next) RETURNING id');
        $stmt->execute(['name'=>$name,'cron'=>$cron,'timezone'=>$timezone,'type'=>$job->type,'payload'=>json_encode($payload, JSON_THROW_ON_ERROR),'queue'=>$queue,'next'=>$next->format('Y-m-d H:i:sP')]);
        return ['id'=>$stmt->fetchColumn(),'name'=>$name,'next_run_at'=>$next->format(DATE_ATOM)];
    }

    public function tasks(): array
    {
        return $this->pdo()->query('SELECT id,name,cron_expression,timezone,job_type,queue,enabled,last_run_at,next_run_at,last_error FROM scheduler_tasks ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pendingDeliveries(int $limit=50): array
    {
        if ($limit<1 || $limit>200) throw new InvalidArgumentException('Invalid outbox limit.');
        $stmt=$this->pdo()->prepare("SELECT o.id,t.name AS task,o.queue,o.job_type,o.due_at,o.dispatch_attempts,o.next_attempt_at,o.last_error FROM scheduler_outbox o JOIN scheduler_tasks t ON t.id=o.task_id WHERE o.status='pending' ORDER BY o.next_attempt_at,o.created_at,o.id LIMIT :limit");
        $stmt->bindValue('limit',$limit,PDO::PARAM_INT);$stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setEnabled(string $name, bool $enabled): bool
    {
        $stmt=$this->pdo()->prepare('UPDATE scheduler_tasks SET enabled=:enabled,last_error=NULL,updated_at=clock_timestamp() WHERE name=:name');
        $stmt->bindValue(':enabled',$enabled,PDO::PARAM_BOOL);
        $stmt->bindValue(':name',$name);
        $stmt->execute();
        return $stmt->rowCount()===1;
    }

    public function tick(int $batch = 25): array
    {
        if ($batch < 1 || $batch > 200) throw new InvalidArgumentException('Scheduler batch must be 1..200.');
        if ($this->db->driver() !== 'pgsql') throw new RuntimeException('Scheduler requires PostgreSQL.');
        $scheduled=0;$scheduleErrors=0;$coalesced=0;
        for ($i=0;$i<$batch;$i++) {
            $did=$this->db->transaction(function(PDO $pdo): int {
                $row=$pdo->query("SELECT id,name,cron_expression,timezone,job_type,payload,queue,next_run_at FROM scheduler_tasks WHERE enabled=true AND next_run_at<=now() ORDER BY next_run_at,id FOR UPDATE SKIP LOCKED LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) return 0;
                try {
                    if (!$this->handlers->supports((string)$row['job_type']) || $row['queue']==='connectors') throw new InvalidArgumentException('Invalid scheduled handler.');
                    $due=new DateTimeImmutable((string)$row['next_run_at']);
                    // Coalesce missed occurrences after downtime; do not flood the queue with historical diagnostics.
                    $now=new DateTimeImmutable((string)$pdo->query('SELECT clock_timestamp()')->fetchColumn());
                    $next=(new ScheduledTask((string)$row['cron_expression'],(string)$row['timezone']))->next($now);
                    $payload=json_decode((string)$row['payload'],true,32,JSON_THROW_ON_ERROR);
                    if (!is_array($payload)) throw new InvalidArgumentException('Invalid scheduled task payload.');
                    $job=QueuedJob::create((string)$row['job_type'],$payload,(string)$row['queue']);
                } catch (InvalidArgumentException|\JsonException|\DateTimeException|\RuntimeException $e) {
                    if ($e instanceof \PDOException) throw $e;
                    $bad=$pdo->prepare("UPDATE scheduler_tasks SET enabled=false,last_error='invalid_schedule',updated_at=clock_timestamp() WHERE id=:id");
                    $bad->execute(['id'=>$row['id']]);return -1;
                }
                // A slow/unavailable queue must not accumulate an unbounded backlog for one task.
                $pending=$pdo->prepare("SELECT 1 FROM scheduler_outbox WHERE task_id=:id AND status='pending' LIMIT 1");
                $pending->execute(['id'=>$row['id']]);
                if ($pending->fetchColumn()!==false) {
                    $skip=$pdo->prepare('UPDATE scheduler_tasks SET next_run_at=:next,updated_at=clock_timestamp() WHERE id=:id');
                    $skip->execute(['next'=>$next->format('Y-m-d H:i:sP'),'id'=>$row['id']]);return 2;
                }
                $out=$pdo->prepare('INSERT INTO scheduler_outbox (id,task_id,due_at,job_type,payload,queue,max_attempts) VALUES (:id,:task,:due,:type,CAST(:payload AS jsonb),:queue,:max) ON CONFLICT (task_id,due_at) DO NOTHING');
                $out->execute(['id'=>$job->id,'task'=>$row['id'],'due'=>$due->format('Y-m-d H:i:sP'),'type'=>$job->type,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'queue'=>$job->queue,'max'=>$job->maxAttempts]);
                $update=$pdo->prepare('UPDATE scheduler_tasks SET last_run_at=:last,next_run_at=:next,updated_at=now() WHERE id=:id');
                $update->execute(['last'=>$due->format('Y-m-d H:i:sP'),'next'=>$next->format('Y-m-d H:i:sP'),'id'=>$row['id']]);
                return 1;
            });
            if ($did===0) break;
            if ($did<0) $scheduleErrors++; elseif ($did===2) $coalesced++; else $scheduled++;
        }
        $dispatched=0;$errors=0;
        for ($i=0;$i<$batch;$i++) {
            // Row lock spans delivery and receipt recording. A nested savepoint makes a PostgreSQL
            // push error recoverable without losing the retry metadata; Redis push is idempotent.
            $pushed=$this->db->transaction(function(PDO $pdo): int {
                $row=$pdo->query("SELECT id,job_type,payload,queue,max_attempts,dispatch_attempts FROM scheduler_outbox WHERE status='pending' AND next_attempt_at<=clock_timestamp() ORDER BY next_attempt_at,created_at,id FOR UPDATE SKIP LOCKED LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) return 0;
                try {
                    $this->db->transaction(function(PDO $pdo)use($row):void {
                        $payload=json_decode((string)$row['payload'],true,32,JSON_THROW_ON_ERROR);
                        if (!is_array($payload) || $row['queue']==='connectors' || !$this->handlers->supports((string)$row['job_type'])) throw new InvalidArgumentException('Invalid scheduled outbox job.');
                        $job=new QueuedJob((string)$row['id'],(string)$row['job_type'],$payload,(string)$row['queue'],(int)$row['max_attempts']);
                        $this->queue->push($job);
                    });
                } catch (Throwable) {
                    $delay=min(900, 5 * 2 ** min(8,(int)$row['dispatch_attempts']) + random_int(0,5));
                    $stmt=$pdo->prepare("UPDATE scheduler_outbox SET dispatch_attempts=LEAST(dispatch_attempts+1::bigint,2147483647),next_attempt_at=clock_timestamp()+(:delay*interval '1 second'),last_error='dispatch_failed' WHERE id=:id");
                    $stmt->execute(['delay'=>$delay,'id'=>$row['id']]);return -1;
                }
                $stmt=$pdo->prepare("UPDATE scheduler_outbox SET status='sent',sent_at=clock_timestamp(),last_error=NULL WHERE id=:id");
                $stmt->execute(['id'=>$row['id']]);return 1;
            });
            if ($pushed===0) break;
            if ($pushed<0) $errors++; else $dispatched++;
        }
        $connectorScheduled=$this->connectors?->tick($batch)??0;
        return ['scheduled'=>$scheduled,'dispatched'=>$dispatched,'dispatch_errors'=>$errors,'schedule_errors'=>$scheduleErrors,'coalesced'=>$coalesced,'connector_ticks'=>$connectorScheduled];
    }

    private function pdo(): PDO
    {
        if ($this->db->driver()!=='pgsql') throw new RuntimeException('Scheduler requires PostgreSQL.');
        return $this->db->connection();
    }
}
