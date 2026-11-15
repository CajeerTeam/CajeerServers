<?php
declare(strict_types=1);

namespace Cajeer\Servers\Queue;

use Cajeer\Servers\Logging\JsonLogger;
use Cajeer\Servers\Observability\PrometheusExporter;
use Cajeer\Servers\Observability\OpenTelemetryTracer;
use RuntimeException;
use Throwable;

/** Worker with bounded workload, exponential retries and persistent outcome. */
final class JobWorker
{
    private bool $stopping = false;

    public function __construct(private readonly QueueDriverInterface $queue, private readonly JobProcessor $processor, private readonly JsonLogger $logger,
        private readonly ?PrometheusExporter $metrics = null, private readonly ?OpenTelemetryTracer $tracer = null) {}

    public function stop(): void { $this->stopping = true; }

    public function once(string $name = 'default', int $leaseSeconds = 60, bool $watchdog = false): ?array
    {
        if ($leaseSeconds<5 || $leaseSeconds>3600) throw new RuntimeException('Invalid lease duration.');
        if (!$watchdog) return $this->handle($name,$leaseSeconds,false);
        if (!function_exists('pcntl_alarm') || !function_exists('pcntl_signal_get_handler')) throw new RuntimeException('Queue worker requires CLI pcntl.');
        $previous=pcntl_signal_get_handler(SIGALRM);
        $term=pcntl_signal_get_handler(SIGTERM);$int=pcntl_signal_get_handler(SIGINT);
        $async=pcntl_async_signals(true);
        pcntl_signal(SIGTERM,fn()=> $this->stop());pcntl_signal(SIGINT,fn()=> $this->stop());
        pcntl_signal(SIGALRM,SIG_DFL);
        pcntl_alarm($leaseSeconds-1);
        try { return $this->handle($name,$leaseSeconds,true); }
        finally { pcntl_alarm(0);pcntl_signal(SIGALRM,$previous);pcntl_signal(SIGTERM,$term);pcntl_signal(SIGINT,$int);pcntl_async_signals($async); }
    }

    private function handle(string $name,int $leaseSeconds,bool $watchdog): ?array
    {
        if ($name==='connectors') throw new RuntimeException('Use connector:work for the reserved connector queue.');
        if ($this->stopping) return null;
        $reserved = $this->queue->reserve($name,$leaseSeconds);
        if ($reserved === null) return null;
        $job=$reserved->job;
        $started = hrtime(true);
        try {
            $checkpoint=function()use($reserved,$leaseSeconds,$watchdog):void {
                // Arm before the renewal round trip: network latency cannot extend the local deadline.
                if ($watchdog) pcntl_alarm($leaseSeconds-1);
                $this->queue->renew($reserved,$leaseSeconds);
            };
            $checkpoint();
            $output=$this->processor->process($job,$checkpoint);
        } catch (LeaseLost) {
            return $this->lost($reserved);
        } catch (Throwable $e) {
            $delay = min(3600, 2 ** min(10,$reserved->attempt) + random_int(0, 2 ** min(8,$reserved->attempt)));
            // Never persist raw exception messages that may expose connection secrets.
            $reason = 'Handler failure: ' . $e::class;
            try { $this->queue->release($reserved,$reason,$delay); }
            catch (LeaseLost) { return $this->lost($reserved); }
            $failed = $reserved->attempt >= $job->maxAttempts;
            $this->observe($job->type, false, (hrtime(true) - $started) / 1_000_000_000);
            $this->logger->log('warning','queue.handler_failed',['job_id'=>$job->id,'job_type'=>$job->type,'attempt'=>$reserved->attempt,'terminal'=>$failed,'error_class'=>$e::class]);
            return ['id'=>$job->id,'status'=>$failed?'failed':'retry_scheduled','attempt'=>$reserved->attempt];
        }
        // A stale receipt is a fencing failure, never turn it into a retry of a finished task.
        try { $this->queue->succeed($reserved,$output); }
        catch (LeaseLost) { return $this->lost($reserved); }
        $this->observe($job->type, true, (hrtime(true) - $started) / 1_000_000_000);
        $this->logger->info('queue.completed',['job_id'=>$job->id,'job_type'=>$job->type,'attempt'=>$reserved->attempt]);
        return ['id'=>$job->id,'status'=>'completed','attempt'=>$reserved->attempt];
    }

    private function lost(QueueReservation $r): array
    {
        $this->logger->log('warning','queue.lease_lost',['job_id'=>$r->job->id,'job_type'=>$r->job->type,'attempt'=>$r->attempt]);
        return ['id'=>$r->job->id,'status'=>'lease_lost','attempt'=>$r->attempt];
    }

    private function observe(string $type, bool $success, float $elapsed): void
    {
        try { $this->metrics?->observeJob($type, $success, $elapsed); }
        catch (Throwable) { error_log('CajeerServers: queue metrics write failed.'); }
        try { $this->tracer?->recordJob($type, $success, $elapsed); }
        catch (Throwable) { error_log('CajeerServers: queue tracing export failed.'); }
    }

    /** @return array{processed:int,failed:int,lease_lost:int} */
    public function run(string $name = 'default', int $maxJobs = 0, int $maxSeconds = 0, int $sleepMilliseconds = 500, int $leaseSeconds = 60, bool $watchdog = false): array
    {
        if ($maxJobs < 0 || $maxJobs > 100000 || $maxSeconds < 0 || $maxSeconds > 86400 || $sleepMilliseconds < 50 || $sleepMilliseconds > 10000) {
            throw new RuntimeException('Invalid worker limits.');
        }
        if ($maxJobs === 0 && $maxSeconds === 0) throw new RuntimeException('Provide --max-jobs or --max-seconds for a bounded worker.');
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM,fn()=> $this->stop());
            pcntl_signal(SIGINT,fn()=> $this->stop());
        }
        $started=hrtime(true);$processed=0;$failed=0;$lost=0;
        while(!$this->stopping && ($maxJobs===0 || $processed<$maxJobs) && ($maxSeconds===0 || (hrtime(true)-$started)/1_000_000_000<$maxSeconds)) {
            $result=$this->once($name,$leaseSeconds,$watchdog);
            if ($result===null) { usleep($sleepMilliseconds*1000); continue; }
            $processed++;
            if ($result['status']==='failed') $failed++;
            if ($result['status']==='lease_lost') $lost++;
        }
        return ['processed'=>$processed,'failed'=>$failed,'lease_lost'=>$lost];
    }
}
