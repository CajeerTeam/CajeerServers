<?php
declare(strict_types=1);

namespace Cajeer\Servers\Scheduler;

use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Queue\JobProcessor;
use Cajeer\Servers\Queue\QueuedJob;
use Cajeer\Servers\Queue\QueueDriverInterface;
use DateTimeImmutable;
use DateTimeZone;
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
        if (!$this->handlers->supports($jobType)) throw new InvalidArgumentException('Unregistered scheduled job handler.');
        $job=QueuedJob::create($jobType,$payload,$queue);
        $schedule=new ScheduledTask($cron,$timezone);
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $next=$runNow ? $now : $schedule->next($now);
        $stmt=$this->pdo()->prepare('INSERT INTO scheduler_tasks (name,cron_expression,timezone,job_type,payload,queue,next_run_at) VALUES (:name,:cron,:timezone,:type,CAST(:payload AS jsonb),:queue,:next) RETURNING id');
        $stmt->execute(['name'=>$name,'cron'=>$cron,'timezone'=>$timezone,'type'=>$job->type,'payload'=>json_encode($payload, JSON_THROW_ON_ERROR),'queue'=>$queue,'next'=>$next->format('Y-m-d H:i:sP')]);
        return ['id'=>$stmt->fetchColumn(),'name'=>$name,'next_run_at'=>$next->format(DATE_ATOM)];
    }

    public function tasks(): array
    {
        return $this->pdo()->query('SELECT id,name,cron_expression,timezone,job_type,queue,enabled,last_run_at,next_run_at FROM scheduler_tasks ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setEnabled(string $name, bool $enabled): bool
    {
        $stmt=$this->pdo()->prepare('UPDATE scheduler_tasks SET enabled=:enabled,updated_at=now() WHERE name=:name');
        $stmt->bindValue(':enabled',$enabled,PDO::PARAM_BOOL);
        $stmt->bindValue(':name',$name);
        $stmt->execute();
        return $stmt->rowCount()===1;
    }

    public function tick(int $batch = 25): array
    {
        if ($batch < 1 || $batch > 200) throw new InvalidArgumentException('Scheduler batch must be 1..200.');
        if ($this->db->driver() !== 'pgsql') throw new RuntimeException('Scheduler requires PostgreSQL.');
        $scheduled=0;
        for ($i=0;$i<$batch;$i++) {
            $did=$this->db->transaction(function(PDO $pdo): bool {
                $row=$pdo->query("SELECT id,name,cron_expression,timezone,job_type,payload,queue,next_run_at FROM scheduler_tasks WHERE enabled=true AND next_run_at<=now() ORDER BY next_run_at,id FOR UPDATE SKIP LOCKED LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) return false;
                if (!$this->handlers->supports((string)$row['job_type'])) throw new RuntimeException('Scheduled task has an unregistered handler.');
                $due=new DateTimeImmutable((string)$row['next_run_at']);
                $next=(new ScheduledTask((string)$row['cron_expression'],(string)$row['timezone']))->next($due);
                $payload=json_decode((string)$row['payload'],true,32,JSON_THROW_ON_ERROR);
                if (!is_array($payload)) throw new RuntimeException('Invalid scheduled task payload.');
                $job=QueuedJob::create((string)$row['job_type'],$payload,(string)$row['queue']);
                $out=$pdo->prepare('INSERT INTO scheduler_outbox (id,task_id,due_at,job_type,payload,queue,max_attempts) VALUES (:id,:task,:due,:type,CAST(:payload AS jsonb),:queue,:max) ON CONFLICT (task_id,due_at) DO NOTHING');
                $out->execute(['id'=>$job->id,'task'=>$row['id'],'due'=>$due->format('Y-m-d H:i:sP'),'type'=>$job->type,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'queue'=>$job->queue,'max'=>$job->maxAttempts]);
                $update=$pdo->prepare('UPDATE scheduler_tasks SET last_run_at=:last,next_run_at=:next,updated_at=now() WHERE id=:id');
                $update->execute(['last'=>$due->format('Y-m-d H:i:sP'),'next'=>$next->format('Y-m-d H:i:sP'),'id'=>$row['id']]);
                return true;
            });
            if (!$did) break;
            $scheduled++;
        }
        $dispatched=0;$errors=0;
        for ($i=0;$i<$batch;$i++) {
            try {
                $pushed=$this->db->transaction(function(PDO $pdo): bool {
                    $row=$pdo->query("SELECT id,job_type,payload,queue,max_attempts FROM scheduler_outbox WHERE status='pending' ORDER BY created_at,id FOR UPDATE SKIP LOCKED LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($row)) return false;
                    $payload=json_decode((string)$row['payload'],true,32,JSON_THROW_ON_ERROR);
                    if (!is_array($payload)) throw new RuntimeException('Invalid scheduled outbox JSON.');
                    $job=new QueuedJob((string)$row['id'],(string)$row['job_type'],$payload,(string)$row['queue'],(int)$row['max_attempts']);
                    // Queue drivers dedupe by the outbox UUID. Crash after push but before commit is safe.
                    $this->queue->push($job);
                    $stmt=$pdo->prepare("UPDATE scheduler_outbox SET status='sent',sent_at=now() WHERE id=:id");
                    $stmt->execute(['id'=>$row['id']]);
                    return true;
                });
            } catch (Throwable) {
                $errors++;
                // Outbox remains pending; a later tick will retry. Stop to avoid a hot failure loop.
                break;
            }
            if (!$pushed) break;
            $dispatched++;
        }
        $connectorScheduled=$this->connectors?->tick($batch)??0;
        return ['scheduled'=>$scheduled,'dispatched'=>$dispatched,'dispatch_errors'=>$errors,'connector_ticks'=>$connectorScheduled];
    }

    private function pdo(): PDO
    {
        if ($this->db->driver()!=='pgsql') throw new RuntimeException('Scheduler requires PostgreSQL.');
        return $this->db->connection();
    }
}
