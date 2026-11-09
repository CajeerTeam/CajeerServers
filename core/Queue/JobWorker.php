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

    public function once(string $name = 'default', int $leaseSeconds = 60): ?array
    {
        $reserved = $this->queue->reserve($name,$leaseSeconds);
        if ($reserved === null) return null;
        $job=$reserved->job;
        $started = hrtime(true);
        try {
            $output=$this->processor->process($job);
        } catch (Throwable $e) {
            $delay = min(3600, 2 ** min(10,$reserved->attempt));
            // Never persist raw exception messages that may expose connection secrets.
            $reason = 'Handler failure: ' . $e::class;
            $this->queue->release($reserved,$reason,$delay);
            $failed = $reserved->attempt >= $job->maxAttempts;
            $this->observe($job->type, false, (hrtime(true) - $started) / 1_000_000_000);
            $this->logger->log('warning','queue.handler_failed',['job_id'=>$job->id,'job_type'=>$job->type,'attempt'=>$reserved->attempt,'terminal'=>$failed,'error_class'=>$e::class]);
            return ['id'=>$job->id,'status'=>$failed?'failed':'retry_scheduled','attempt'=>$reserved->attempt];
        }
        // A stale receipt is a fencing failure, never turn it into a retry of a finished task.
        $this->queue->succeed($reserved,$output);
        $this->observe($job->type, true, (hrtime(true) - $started) / 1_000_000_000);
        $this->logger->info('queue.completed',['job_id'=>$job->id,'job_type'=>$job->type,'attempt'=>$reserved->attempt]);
        return ['id'=>$job->id,'status'=>'completed','attempt'=>$reserved->attempt];
    }

    private function observe(string $type, bool $success, float $elapsed): void
    {
        try { $this->metrics?->observeJob($type, $success, $elapsed); }
        catch (Throwable) { error_log('CajeerServers: queue metrics write failed.'); }
        try { $this->tracer?->recordJob($type, $success, $elapsed); }
        catch (Throwable) { error_log('CajeerServers: queue tracing export failed.'); }
    }

    /** @return array{processed:int,failed:int} */
    public function run(string $name = 'default', int $maxJobs = 0, int $maxSeconds = 0, int $sleepMilliseconds = 500, int $leaseSeconds = 60): array
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
        $started=microtime(true);$processed=0;$failed=0;
        while(!$this->stopping && ($maxJobs===0 || $processed<$maxJobs) && ($maxSeconds===0 || microtime(true)-$started<$maxSeconds)) {
            $result=$this->once($name,$leaseSeconds);
            if ($result===null) { usleep($sleepMilliseconds*1000); continue; }
            $processed++;
            if ($result['status']==='failed') $failed++;
        }
        return ['processed'=>$processed,'failed'=>$failed];
    }
}
