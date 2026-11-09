<?php
declare(strict_types=1);

namespace Cajeer\Servers\Cli;

use Cajeer\Servers\Cache\RedisCacheStore;
use Cajeer\Servers\Installer\InstallState;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Logging\JsonLogger;
use Cajeer\Servers\Queue\JobProcessor;
use Cajeer\Servers\Queue\JobWorker;
use Cajeer\Servers\Queue\QueuedJob;
use Cajeer\Servers\Queue\QueueDriverInterface;
use Cajeer\Servers\Queue\RedisQueueDriver;
use Cajeer\Servers\Scheduler\Scheduler;
use Cajeer\Servers\Storage\StorageDiskInterface;
use InvalidArgumentException;
use RuntimeException;

/** Actual operations only; strict CLI flags and explicit failures. */
final class InfrastructureCommands
{
    public function __construct(private readonly Application $app) {}

    /** @param list<string> $argv */
    public function run(string $command, array $argv): int
    {
        $args=$this->options(array_slice($argv,2));
        $c=$this->app->container();
        if (in_array($command,['queue:push','queue:work','queue:retry','queue:prune','scheduler:add','scheduler:run','scheduler:enable','scheduler:disable'],true)
            && !$c->get(InstallState::class)->isInstalled()) {
            throw new RuntimeException('Install CajeerServers before starting background operations.');
        }
        return match ($command) {
            'cache:check' => $this->output(['ok'=>$c->get(RedisCacheStore::class)->ping()]),
            'cache:get' => $this->cacheGet($args),
            'cache:put' => $this->cachePut($args),
            'cache:delete' => $this->output(['deleted'=>$c->get(RedisCacheStore::class)->delete($this->required($args,'key'))]),
            'queue:push' => $this->push($args),
            'queue:work' => $this->work($args),
            'queue:status' => $this->output(['job'=>$c->get(QueueDriverInterface::class)->find($this->required($args,'id'))]),
            'queue:failed' => $this->failed($args),
            'queue:retry' => $this->output(['retried'=>$c->get(QueueDriverInterface::class)->retry($this->required($args,'id'))]),
            'queue:prune' => $this->output(['deleted'=>$c->get(QueueDriverInterface::class)->prune($this->number($args,'days',7,1,3650),$args['queue']??'default',$this->number($args,'limit',500,1,2000))]),
            'scheduler:add' => $this->scheduleAdd($args),
            'scheduler:run' => $this->scheduleRun($args),
            'scheduler:list' => $this->output(['tasks'=>$c->get(Scheduler::class)->tasks()]),
            'scheduler:enable','scheduler:disable' => $this->output(['updated'=>$c->get(Scheduler::class)->setEnabled($this->required($args,'name'),$command==='scheduler:enable')]),
            'storage:check' => $this->storageCheck(),
            'storage:put' => $this->storagePut($args),
            'storage:get' => $this->storageGet($args),
            'storage:exists' => $this->output(['exists'=>$c->get(StorageDiskInterface::class)->exists($this->required($args,'key'))]),
            'storage:delete' => $this->output(['deleted'=>$c->get(StorageDiskInterface::class)->delete($this->required($args,'key'))]),
            default => throw new InvalidArgumentException('Unsupported infrastructure CLI command.'),
        };
    }

    private function cacheGet(array $args): int
    {
        $value=$this->app->container()->get(RedisCacheStore::class)->get($this->required($args,'key'));
        return $this->output(['found'=>$value!==null,'value'=>$value]);
    }

    private function cachePut(array $args): int
    {
        $this->app->container()->get(RedisCacheStore::class)->put($this->required($args,'key'),$this->required($args,'value'),$this->number($args,'ttl',300,1,31536000));
        return $this->output(['stored'=>true]);
    }

    private function push(array $args): int
    {
        $type=$this->required($args,'type');
        if (!$this->app->container()->get(JobProcessor::class)->supports($type)) throw new InvalidArgumentException('Unregistered job type.');
        $job=QueuedJob::create($type,$this->payload($args),$args['queue']??'default',$this->number($args,'attempts',3,1,25));
        $id=$this->app->container()->get(QueueDriverInterface::class)->push($job,$this->number($args,'delay',0,0,2592000));
        return $this->output(['id'=>$id,'status'=>'queued']);
    }

    private function work(array $args): int
    {
        $once=isset($args['once']) || (!isset($args['max-jobs']) && !isset($args['max-seconds']));
        $worker=$this->app->container()->get(JobWorker::class);
        $queue=$args['queue']??'default';
        $lease=$this->number($args,'lease',60,5,3600);
        if ($once) return $this->output(['job'=>$worker->once($queue,$lease)]);
        return $this->output($worker->run($queue,$this->number($args,'max-jobs',0,0,100000),
            $this->number($args,'max-seconds',0,0,86400),$this->number($args,'sleep-ms',500,50,10000),$lease));
    }

    private function failed(array $args): int
    {
        $queue=$this->app->container()->get(QueueDriverInterface::class);
        $limit=$this->number($args,'limit',50,1,200);
        $rows=$queue instanceof RedisQueueDriver ? $queue->failedInQueue($args['queue']??'default',$limit) : $queue->failed($limit);
        return $this->output(['jobs'=>$rows]);
    }

    private function scheduleRun(array $args): int
    {
        if ($this->app->config()->get('scheduler.enabled', true) !== true) throw new RuntimeException('Scheduler is disabled.');
        $result = $this->app->container()->get(Scheduler::class)->tick($this->number($args,'batch',25,1,200));
        $this->output($result);
        return $result['dispatch_errors'] === 0 ? 0 : 1;
    }

    private function scheduleAdd(array $args): int
    {
        $result=$this->app->container()->get(Scheduler::class)->add(
            $this->required($args,'name'),$this->required($args,'cron'),$args['timezone']??'UTC',
            $this->required($args,'type'),$this->payload($args),$args['queue']??'default',isset($args['run-now']));
        return $this->output($result);
    }

    private function storageCheck(): int
    {
        // Real read/write probe. No untrusted path and no persistent test object.
        $disk=$this->app->container()->get(StorageDiskInterface::class);
        $key='_health/'.bin2hex(random_bytes(12));$content=random_bytes(128);
        $disk->put($key,$content);
        try {
            $ok=$disk->exists($key) && hash_equals($content,$disk->get($key));
            if (!$ok) throw new RuntimeException('Storage read-after-write check failed.');
        } finally { $disk->delete($key); }
        return $this->output(['ok'=>true,'read_write_delete'=>true]);
    }

    private function storagePut(array $args): int
    {
        $contents=$this->readFile($this->required($args,'file'),16*1024*1024);
        $this->app->container()->get(StorageDiskInterface::class)->put($this->required($args,'key'),$contents);
        return $this->output(['stored'=>true,'bytes'=>strlen($contents)]);
    }

    private function storageGet(array $args): int
    {
        $target=$this->required($args,'output');
        if (file_exists($target) || is_link($target)) throw new RuntimeException('Refusing to overwrite an existing output file.');
        $dir=realpath(dirname($target));
        if ($dir===false || !is_dir($dir) || !is_writable($dir)) throw new RuntimeException('Output directory is unavailable.');
        $content=$this->app->container()->get(StorageDiskInterface::class)->get($this->required($args,'key'));
        $temporary=tempnam($dir,'.cajeer-download-');
        if ($temporary===false) throw new RuntimeException('Cannot allocate output temporary file.');
        try {
            if (!chmod($temporary,0600) || file_put_contents($temporary,$content,LOCK_EX)!==strlen($content) || file_exists($target) || !rename($temporary,$target)) throw new RuntimeException('Cannot safely save downloaded file.');
        } finally { if (is_file($temporary)) @unlink($temporary); }
        return $this->output(['downloaded'=>true,'bytes'=>strlen($content)]);
    }

    private function payload(array $args): array
    {
        if (!isset($args['payload-file'])) return [];
        $data=json_decode($this->readFile($args['payload-file'],65536),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($data) || !($data===[] || !array_is_list($data))) throw new InvalidArgumentException('Payload must be a JSON object.');
        return $data;
    }

    private function readFile(string $path,int $limit): string
    {
        if (is_link($path) || !is_file($path) || !is_readable($path) || filesize($path)>$limit) throw new RuntimeException('Input file unavailable or too large.');
        $data=@file_get_contents($path);
        if (!is_string($data) || strlen($data)>$limit) throw new RuntimeException('Cannot safely read input file.');
        return $data;
    }

    private function options(array $argv): array
    {
        $result=[];
        foreach ($argv as $arg) {
            if (!preg_match('/^--([a-z][a-z0-9-]*)(?:=(.*))?$/sD',$arg,$match)) throw new InvalidArgumentException('Expected --name=value options.');
            $key=$match[1];
            if (!in_array($key,['id','key','value','ttl','queue','type','payload-file','delay','attempts','days','once','max-jobs','max-seconds','sleep-ms','lease','limit','name','cron','timezone','run-now','batch','file','output'],true) || array_key_exists($key,$result)) {
                throw new InvalidArgumentException('Unknown or duplicate CLI option: --'.$key);
            }
            if (!isset($match[2]) && !in_array($key,['once','run-now'],true)) throw new InvalidArgumentException('CLI option requires a value: --'.$key);
            if (isset($match[2]) && in_array($key,['once','run-now'],true)) throw new InvalidArgumentException('CLI flag must not have a value: --'.$key);
            $result[$key]=$match[2]??'1';
        }
        return $result;
    }

    private function number(array $args,string $key,int $default,int $min,int $max): int
    {
        $v=$args[$key]??(string)$default;
        if (!ctype_digit($v) || strlen($v)>10 || (int)$v<$min || (int)$v>$max) throw new InvalidArgumentException('Invalid numeric CLI option --'.$key);
        return (int)$v;
    }

    private function required(array $args,string $key): string
    {
        $v=$args[$key]??'';
        if ($v==='') throw new InvalidArgumentException('Missing required --'.$key.'=... option.');
        return $v;
    }

    private function output(array $result): int
    {
        fwrite(STDOUT,json_encode(JsonLogger::redact($result),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
        return 0;
    }
}
