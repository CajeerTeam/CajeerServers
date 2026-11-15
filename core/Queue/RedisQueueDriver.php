<?php
declare(strict_types=1);

namespace Cajeer\Servers\Queue;

use Cajeer\Servers\Cache\RedisClient;
use InvalidArgumentException;
use RuntimeException;

/** Redis-backed durable queue with atomic Lua transitions, leases, fencing and DLQ. */
final class RedisQueueDriver implements QueueDriverInterface
{
    public function __construct(private readonly RedisClient $redis, private readonly string $prefix = 'cajeer:queue:')
    {
        if (!preg_match('/^[a-zA-Z0-9:_\-.]{1,90}$/D', $prefix)) throw new InvalidArgumentException('Invalid queue key prefix.');
    }

    public function push(QueuedJob $job, int $delaySeconds = 0): string
    {
        if ($job->queue==='connectors' || $job->type==='connector.execute') throw new InvalidArgumentException('Connector queue requires PostgreSQL.');
        if ($delaySeconds < 0 || $delaySeconds > 2592000) throw new InvalidArgumentException('Invalid job delay.');
        $script = <<<'LUA'
local existing=redis.call('GET',KEYS[1]); if existing then return 0 end
local t=redis.call('TIME'); local now=tonumber(t[1]);
local j=cjson.decode(ARGV[1]); j.payload_json=ARGV[3]; j.status='queued'; j.attempts=0; j.last_error=cjson.null;
j.result=cjson.null; j.created_at=now; j.finished_at=cjson.null; j.lease_token=cjson.null;
redis.call('SET',KEYS[1],cjson.encode(j)); redis.call('ZADD',KEYS[2],now+tonumber(ARGV[2]),j.id);return 1
LUA;
        $ok = $this->redis->command('EVAL',$script,'2',$this->jobKey($job->id),$this->ready($job->queue),json_encode($job->data(), JSON_THROW_ON_ERROR), (string)$delaySeconds,json_encode($job->payload,JSON_THROW_ON_ERROR));
        if ($ok === 0) {
            $existing = $this->find($job->id);
            if ($existing === null || $existing['type'] !== $job->type || $existing['queue'] !== $job->queue || !QueuedJob::samePayload($existing['payload'], $job->payload) || (int)$existing['max_attempts'] !== $job->maxAttempts) {
                throw new RuntimeException('Queue job ID conflicts with a different job.');
            }
        }
        return $job->id;
    }

    public function reserve(string $queue = 'default', int $leaseSeconds = 60): ?QueueReservation
    {
        PostgresQueueDriver::validateQueue($queue);
        if ($queue==='connectors') throw new InvalidArgumentException('Connector queue requires PostgreSQL.');
        if ($leaseSeconds < 5 || $leaseSeconds > 3600) throw new InvalidArgumentException('Invalid lease duration.');
        $receipt = bin2hex(random_bytes(24));
        $script = <<<'LUA'
local t=redis.call('TIME');local now=tonumber(t[1]);
local expired=redis.call('ZRANGEBYSCORE',KEYS[2],'-inf',now,'LIMIT',0,100)
for _,id in ipairs(expired) do
 redis.call('ZREM',KEYS[2],id)
 local key=ARGV[1]..id;local raw=redis.call('GET',key)
 if raw then
  local j=cjson.decode(raw)
  if j.status=='running' and j.queue==ARGV[4] and j.reserved_until>now then
   redis.call('ZADD',KEYS[2],j.reserved_until,id)
  elseif j.status=='running' and j.queue==ARGV[4] then
   j.lease_token=cjson.null;j.reserved_until=cjson.null
   if j.attempts>=j.max_attempts then
    j.status='failed';j.last_error='Worker lease expired';j.finished_at=now
    redis.call('ZADD',KEYS[3],now,id)
   else
    j.status='queued';redis.call('ZADD',KEYS[1],now,id)
   end
   redis.call('SET',key,cjson.encode(j))
  end
 end
end
local ids=redis.call('ZRANGEBYSCORE',KEYS[1],'-inf',now,'LIMIT',0,100)
for _,id in ipairs(ids) do
 redis.call('ZREM',KEYS[1],id)
 local raw=redis.call('GET',ARGV[1]..id)
 if raw then
  local j=cjson.decode(raw)
  if j.status=='queued' and j.queue==ARGV[4] then
   if j.attempts>=j.max_attempts then
    j.status='failed';j.last_error='Retry budget exhausted';j.finished_at=now;j.lease_token=cjson.null;j.reserved_until=cjson.null
    redis.call('SET',ARGV[1]..id,cjson.encode(j));redis.call('ZADD',KEYS[3],now,id)
   else
    j.status='running';j.attempts=j.attempts+1;j.lease_token=ARGV[2];j.reserved_until=now+tonumber(ARGV[3]);
    redis.call('SET',ARGV[1]..id,cjson.encode(j));redis.call('ZADD',KEYS[2],j.reserved_until,id)
    return cjson.encode(j)
   end
  end
 end
end
return nil
LUA;
        $raw = $this->redis->command('EVAL',$script,'3',$this->ready($queue),$this->processing($queue),$this->failedKey($queue),$this->prefix.'job:',$receipt,(string)$leaseSeconds,$queue);
        if ($raw === null) return null;
        $row = self::decodeRecord((string)$raw);
        return new QueueReservation(new QueuedJob($row['id'],$row['type'],$row['payload'],$row['queue'],(int)$row['max_attempts']),$receipt,(int)$row['attempts']);
    }

    public function renew(QueueReservation $reservation, int $leaseSeconds): void
    {
        if ($leaseSeconds<5 || $leaseSeconds>3600) throw new InvalidArgumentException('Invalid lease duration.');
        $script=<<<'LUA'
local raw=redis.call('GET',KEYS[1]);if not raw then return 0 end
local j=cjson.decode(raw);local now=tonumber(redis.call('TIME')[1]);
if j.status~='running' or j.lease_token~=ARGV[1] or j.reserved_until<=now or j.queue~=ARGV[3] then return 0 end
j.reserved_until=math.max(j.reserved_until,now+tonumber(ARGV[2]));redis.call('SET',KEYS[1],cjson.encode(j));redis.call('ZADD',KEYS[2],j.reserved_until,j.id);return 1
LUA;
        $ok=$this->redis->command('EVAL',$script,'2',$this->jobKey($reservation->job->id),$this->processing($reservation->job->queue),$reservation->receipt,(string)$leaseSeconds,$reservation->job->queue);
        if ($ok!==1) throw new LeaseLost('Stale queue receipt; renewal denied.');
    }

    public function succeed(QueueReservation $reservation, array $result): void
    {
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) throw new InvalidArgumentException('Job result too large.');
        $script = <<<'LUA'
local raw=redis.call('GET',KEYS[1]);if not raw then return 0 end
local j=cjson.decode(raw);local now=tonumber(redis.call('TIME')[1]);
if j.status~='running' or j.lease_token~=ARGV[1] or j.reserved_until<=now or j.queue~=ARGV[3] then return 0 end
j.status='completed';j.last_error=cjson.null;j.result=cjson.decode(ARGV[2]);j.result_json=ARGV[2];j.lease_token=cjson.null;j.reserved_until=cjson.null;j.finished_at=now
redis.call('SET',KEYS[1],cjson.encode(j));redis.call('ZREM',KEYS[2],j.id);redis.call('ZADD',KEYS[3],now,j.id);return 1
LUA;
        $ok = $this->redis->command('EVAL',$script,'3',$this->jobKey($reservation->job->id),$this->processing($reservation->job->queue),$this->completed($reservation->job->queue),$reservation->receipt,$json,$reservation->job->queue);
        if ($ok !== 1) throw new LeaseLost('Stale queue receipt; job completion denied.');
    }

    public function release(QueueReservation $reservation, string $reason, int $delaySeconds): void
    {
        if ($delaySeconds < 0 || $delaySeconds > 2592000) throw new InvalidArgumentException('Invalid job retry delay.');
        $script = <<<'LUA'
local raw=redis.call('GET',KEYS[1]);if not raw then return 0 end
local j=cjson.decode(raw);local now=tonumber(redis.call('TIME')[1]);
if j.status~='running' or j.lease_token~=ARGV[1] or j.reserved_until<=now or j.queue~=ARGV[4] then return 0 end
redis.call('ZREM',KEYS[2],j.id); j.last_error=ARGV[2];j.lease_token=cjson.null;j.reserved_until=cjson.null
if j.attempts>=j.max_attempts then
 j.status='failed';j.finished_at=now;redis.call('ZADD',KEYS[4],now,j.id)
else j.status='queued';redis.call('ZADD',KEYS[3],now+tonumber(ARGV[3]),j.id) end
redis.call('SET',KEYS[1],cjson.encode(j));return 1
LUA;
        $ok = $this->redis->command('EVAL',$script,'4',$this->jobKey($reservation->job->id),$this->processing($reservation->job->queue),$this->ready($reservation->job->queue),$this->failedKey($reservation->job->queue),$reservation->receipt,substr($reason,0,512),(string)$delaySeconds,$reservation->job->queue);
        if ($ok !== 1) throw new LeaseLost('Stale queue receipt; job release denied.');
    }

    public function find(string $id): ?array
    {
        $raw = $this->redis->command('GET',$this->jobKey($id));
        if ($raw === null) return null;
        return self::decodeRecord((string)$raw);
    }

    private static function decodeRecord(string $raw): array
    {
        $row=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($row)) throw new RuntimeException('Corrupt Redis queue record.');
        foreach (['payload','result'] as $field) {
            if (isset($row[$field.'_json'])) {
                $row[$field]=json_decode($row[$field.'_json'],true,32,JSON_THROW_ON_ERROR);
                unset($row[$field.'_json']);
            }
        }
        return $row;
    }

    public function failed(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 200) throw new InvalidArgumentException('Invalid failed jobs limit.');
        // Namespace discovery is intentionally not implemented via KEYS; use queue-specific CLI listing.
        return $this->failedInQueue('default', $limit);
    }

    public function failedInQueue(string $queue, int $limit = 50): array
    {
        PostgresQueueDriver::validateQueue($queue);
        if ($limit < 1 || $limit > 200) throw new InvalidArgumentException('Invalid failed jobs limit.');
        $ids = $this->redis->command('ZREVRANGE',$this->failedKey($queue),'0',(string)($limit-1));
        $rows=[];
        foreach ($ids ?? [] as $id) {
            $row=$this->find((string)$id);
            if ($row!==null && ($row['status']??'')==='failed') $rows[]=$row;
        }
        return $rows;
    }

    public function retry(string $id): bool
    {
        $row = $this->find($id);
        if ($row === null || $row['status'] !== 'failed' || $row['queue']==='connectors') return false;
        $queue = $row['queue'];PostgresQueueDriver::validateQueue($queue);
        $script = <<<'LUA'
local raw=redis.call('GET',KEYS[1]);if not raw then return 0 end
local j=cjson.decode(raw);if j.status~='failed' then return 0 end
j.status='queued';j.attempts=0;j.last_error=cjson.null;j.finished_at=cjson.null;j.lease_token=cjson.null;j.reserved_until=cjson.null
redis.call('SET',KEYS[1],cjson.encode(j));redis.call('ZREM',KEYS[3],j.id)
redis.call('ZADD',KEYS[2],tonumber(redis.call('TIME')[1]),j.id);return 1
LUA;
        return $this->redis->command('EVAL',$script,'3',$this->jobKey($id),$this->ready($queue),$this->failedKey($queue))===1;
    }

    public function prune(int $days = 7, string $queue = 'default', int $limit = 500): int
    {
        PostgresQueueDriver::validateQueue($queue);
        if ($queue==='connectors') throw new InvalidArgumentException('Connector queue requires PostgreSQL.');
        if ($days < 1 || $days > 3650 || $limit < 1 || $limit > 2000) throw new InvalidArgumentException('Invalid prune range.');
        $script = <<<'LUA'
local now=tonumber(redis.call('TIME')[1]);local cutoff=now-tonumber(ARGV[1])*86400
local removed=0
for _,set in ipairs(KEYS) do
 local ids=redis.call('ZRANGEBYSCORE',set,'-inf',cutoff,'LIMIT',0,tonumber(ARGV[2])-removed)
 for _,id in ipairs(ids) do
  redis.call('DEL',ARGV[3]..id);redis.call('ZREM',set,id);removed=removed+1
 end
 if removed>=tonumber(ARGV[2]) then break end
end
return removed
LUA;
        return (int) $this->redis->command('EVAL',$script,'2', $this->completed($queue), $this->failedKey($queue),
            (string)$days,(string)$limit,$this->prefix.'job:');
    }

    private function jobKey(string $id): string
    {
        if (!preg_match('/^[a-f0-9-]{36}$/iD',$id)) throw new InvalidArgumentException('Invalid job ID.');
        return $this->prefix.'job:'.$id;
    }
    private function ready(string $q): string { return $this->prefix.'ready:'.$q; }
    private function processing(string $q): string { return $this->prefix.'processing:'.$q; }
    private function failedKey(string $q): string { return $this->prefix.'failed:'.$q; }
    private function completed(string $q): string { return $this->prefix.'completed:'.$q; }
}
