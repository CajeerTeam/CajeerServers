<?php
declare(strict_types=1);

namespace Cajeer\Servers\Api\Controller;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Observability\Diagnostics;
use Cajeer\Servers\Observability\OperationalStats;
use Cajeer\Servers\Queue\JobProcessor;
use Cajeer\Servers\Queue\QueuedJob;
use Cajeer\Servers\Queue\QueueDriverInterface;
use Cajeer\Servers\Scheduler\Scheduler;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use PDO;
use Throwable;

/** Session-bound operational administration. Never accepts arbitrary commands or jobs. */
final class AdminController
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly Rbac $rbac,
        private readonly DatabaseManager $database,
        private readonly QueueDriverInterface $queue,
        private readonly JobProcessor $processor,
        private readonly OperationalStats $stats,
        private readonly Scheduler $scheduler,
        private readonly Diagnostics $diagnostics,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string,mixed> */
    private function principal(HttpRequest $request, ?string $permission = null, bool $mutation = false): array
    {
        $principal = $this->auth->authenticate($request, sessionOnly: true);
        if ($mutation) $this->auth->requireCsrf($request, $principal);
        if ($permission !== null) $this->rbac->requirePermission($principal, $permission);
        return $principal;
    }

    public function session(HttpRequest $request): HttpResponse
    {
        $principal = $this->principal($request);
        return HttpResponse::json([
            'user' => $principal['user'],
            'permissions' => $this->rbac->permissionsFor($principal),
            'auth_type' => 'session',
        ]);
    }

    public function overview(HttpRequest $request): HttpResponse
    {
        $this->principal($request, 'admin.dashboard.read');
        $checks = $this->diagnostics->run(false);
        // Dependency outages must not take down an authenticated dashboard.
        try { $queue = ['available' => true, 'stats' => $this->stats->queue('default')]; }
        catch (Throwable) { $queue = ['available' => false]; }
        try { $scheduler = ['available' => true, 'stats' => $this->stats->scheduler()]; }
        catch (Throwable) { $scheduler = ['available' => false]; }
        return HttpResponse::json([
            'checked_at' => $checks['checked_at'], 'ready' => $checks['ok'],
            'checks' => $checks['checks'], 'queue' => $queue, 'scheduler' => $scheduler,
        ]);
    }

    public function diagnostics(HttpRequest $request): HttpResponse
    {
        $this->principal($request, 'admin.dashboard.read');
        return HttpResponse::json($this->diagnostics->run(true));
    }

    public function jobs(HttpRequest $request): HttpResponse
    {
        $this->principal($request, 'admin.jobs.read');
        $driver = $this->stats->queue('default');
        if ($driver['driver'] !== 'pgsql') {
            // Redis has no general browse API: return aggregate stats and allow lookup by ID.
            return HttpResponse::json(['driver' => $driver['driver'], 'stats' => $driver, 'data' => []]);
        }
        $query = $this->database->connection()->prepare(
            "SELECT id::text, type, status, attempts, max_attempts, created_at, finished_at FROM queue_jobs WHERE queue = 'default' ORDER BY created_at DESC, id DESC LIMIT 30"
        );
        $query->execute();
        return HttpResponse::json(['driver' => $driver['driver'], 'stats' => $driver, 'data' => $query->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function job(HttpRequest $request): HttpResponse
    {
        $this->principal($request, 'admin.jobs.read');
        $id = (string)$request->attribute('id', '');
        if (!self::uuid($id)) throw new HttpException(404, 'Job not found.', 'not_found');
        $job = $this->queue->find($id);
        if (!is_array($job) || ($job['queue'] ?? null) !== 'default' || !$this->processor->supports((string)($job['type'] ?? ''))) {
            throw new HttpException(404, 'Job not found.', 'not_found');
        }
        return HttpResponse::json(['job' => self::publicJob($job)]);
    }

    public function dispatch(HttpRequest $request): HttpResponse
    {
        $principal = $this->principal($request, 'admin.jobs.execute', true);
        $body = $request->attribute('json');
        $type = $body['type'];
        if (!in_array($type, ['runtime.diagnostics', 'storage.probe'], true) || !$this->processor->supports($type)) {
            throw new HttpException(422, 'Job type is not allowed in Admin UI.', 'validation_failed');
        }
        $job = QueuedJob::create($type, [], 'default', 3);
        // Record the request *before* dispatch; no untracked jobs on audit failure.
        $this->audit->record($this->database->connection(), (string)$principal['user']['id'],
            'admin.job.requested', 'success', 'job', $job->id, ['reason' => $type],
            (string)$request->attribute('request_id', ''));
        $this->queue->push($job);
        return HttpResponse::json(['id' => $job->id, 'status' => 'queued', 'type' => $type], 202);
    }

    public function scheduler(HttpRequest $request): HttpResponse
    {
        $this->principal($request, 'admin.scheduler.read');
        $tasks = $this->scheduler->tasks();
        foreach ($tasks as &$task) {
            $task['enabled'] = in_array($task['enabled'], [true, 't', '1', 1], true);
        }
        unset($task);
        return HttpResponse::json(['data' => $tasks, 'stats' => $this->stats->scheduler()]);
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    public static function publicJob(array $job): array
    {
        // Do not return job payload, worker error text, stack traces or arbitrary result data.
        $allowed = ['id','type','status','queue','attempts','max_attempts','created_at','finished_at'];
        $data = [];
        foreach ($allowed as $key) {
            if (isset($job[$key]) && (is_string($job[$key]) || is_int($job[$key]))) $data[$key] = $job[$key];
        }
        if (($job['status'] ?? '') === 'completed') $data['completed'] = true;
        return $data;
    }

    private static function uuid(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id) === 1;
    }
}
