#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Observability\PrometheusExporter;
use Cajeer\Servers\Observability\OpenTelemetryTracer;
use Cajeer\Servers\Observability\OperationalStats;
use Cajeer\Servers\Runtime\HttpRequest;

$assertions = 0;
$check = static function (bool $ok, string $name) use (&$assertions): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    $assertions++;
};
$reject = static function (callable $fn, string $name) use ($check): void {
    try { $fn(); } catch (Throwable) { $check(true, $name); return; }
    throw new RuntimeException('Expected rejection: ' . $name);
};
$path = sys_get_temp_dir() . '/cajeer-obs-' . bin2hex(random_bytes(8));
mkdir($path, 0700);
$process = null;
try {
    $metrics = new PrometheusExporter($path . '/metrics.json');
    $check(str_contains($metrics->render(), 'cajeer_metrics_started_timestamp_seconds'), 'new metrics store initialized');
    $metrics->observe('GET', 200, 0.004);
    $metrics->observe('GET', 503, 0.2);
    $metrics->observe('POST', 422, 2.1);
    $metrics->observe('GET', 200, 12.0);
    $metrics->observe('DYNAMIC_USER_PATH_123', 299, 0.01);
    $metrics->observeJob('runtime.diagnostics', true, 0.3);
    $metrics->observeJob('arbitrary.long.name', false, 0.5);
    $body = $metrics->render();
    $check(str_contains($body, 'cajeer_http_requests_total{method="GET",status_class="2xx"} 2'), 'real HTTP counter persisted');
    $check(str_contains($body, 'cajeer_http_requests_total{method="OTHER",status_class="2xx"} 1'), 'untrusted method collapsed');
    $check(str_contains($body, 'cajeer_queue_jobs_processed_total{type="runtime.diagnostics",outcome="completed"} 1'), 'worker success recorded');
    $check(str_contains($body, 'cajeer_queue_jobs_processed_total{type="other",outcome="failed"} 1'), 'unknown job type collapsed');
    $check(str_contains($body, 'cajeer_http_request_duration_seconds_bucket{method="GET",status_class="2xx",le="+Inf"} 2'), 'histogram inf bucket');
    $check(str_contains($body, 'cajeer_http_request_duration_seconds_sum{method="GET",status_class="2xx"} 12.004000000'), 'sum reflects actual observations');
    $check((fileperms($path . '/metrics.json') & 0077) === 0, 'metrics store private permissions');
    $check(str_contains((new PrometheusExporter($path . '/metrics.json'))->render(), 'status_class="5xx"'), 'survives new process');
    $link = $path . '/evil.json';
    symlink($path . '/metrics.json', $link);
    $reject(fn() => (new PrometheusExporter($link))->observe('GET', 200, 0.1), 'symlink metrics store denied');
    $metrics->observeJob('queue.prune', false, 1.3);
    $check(str_contains($metrics->render(), 'type="queue.prune",outcome="failed"'), 'queue failure tracked');

    $check(!(new OpenTelemetryTracer(false, 'https://otel.example.com', '0.1.8'))->recordHttp('GET', 200, 0.2), 'disabled OTLP is inert');
    $reject(fn() => new OpenTelemetryTracer(true, 'http://example.com:4318', '0.1.8'), 'remote plaintext OTLP rejected');
    $reject(fn() => new OpenTelemetryTracer(true, 'http://admin:pass@127.0.0.1', '0.1.8'), 'embedded credentials rejected');
    $reject(fn() => new OpenTelemetryTracer(true, 'file:///tmp/foo', '0.1.8'), 'file scheme rejected');
    $reject(fn() => new OpenTelemetryTracer(true, 'https://otel.example.com', '0.1.8', 1.5), 'invalid sampling rejected');

    $capture = $path . '/trace.json';
    if (function_exists('proc_open')) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($listener === false) throw new RuntimeException('Cannot allocate test listener.');
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $port = (int) substr(strrchr($address, ':'), 1);
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/otlp-router.php'];
        $env = array_merge($_ENV, ['CAJEER_OTLP_CAPTURE_PATH' => $capture]);
        $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $path . '/http-out', 'w'], 2 => ['file', $path . '/http-err', 'w']], $pipes, __DIR__, $env);
        if (!is_resource($process)) throw new RuntimeException('Failed to start local OTLP test endpoint.');
        fclose($pipes[0]);
        for ($i=0; $i<40; $i++) {
            $status = proc_get_status($process);
            if (!$status['running']) throw new RuntimeException('OTLP fixture stopped early.');
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.05);
            if ($socket) { fclose($socket); break; }
            usleep(50000);
        }
        $tracer = new OpenTelemetryTracer(true, 'http://127.0.0.1:' . $port, '0.1.8', 1.0);
        $check($tracer->recordHttp('GET', 200, 0.012), 'real OTLP/HTTP 202 accepted');
        $spans = json_decode((string)file_get_contents($capture), true);
        $trace = $spans['resourceSpans'][0]['scopeSpans'][0]['spans'][0] ?? [];
        $check(preg_match('/^[0-9a-f]{32}$/D', $trace['traceId'] ?? '') === 1, '128-bit trace ID');
        $check(($trace['kind'] ?? null) === 2, 'HTTP span server kind');
        $check(!str_contains((string)file_get_contents($capture), '/api/v1/auth'), 'no request paths exported');
        $check($tracer->recordJob('runtime.diagnostics', false, 1.25), 'real job trace exported');
        $spans = json_decode((string)file_get_contents($capture), true);
        $trace = $spans['resourceSpans'][0]['scopeSpans'][0]['spans'][0] ?? [];
        $check(($trace['kind'] ?? null) === 3 && ($trace['status']['code'] ?? null) === 2, 'queue span consumer kind and error status');
        $check(!isset($trace['attributes']['payload']), 'no job payload in telemetry');
    }

    putenv('APP_ENV=local');
    putenv('APP_URL=http://127.0.0.1:8080');
    putenv('PROMETHEUS_ENABLED=true');
    putenv('PROMETHEUS_SCRAPE_TOKEN=' . str_repeat('A', 48));
    $app = (new Application(dirname(__DIR__)))->boot();
    try {
        $r = $app->handle(new HttpRequest('GET','/api/v1/live'));
        $check($r->status === 200 && json_decode($r->body, true)['status'] === 'alive', 'liveness endpoint works');
        $r = $app->handle(new HttpRequest('GET','/api/v1/ready'));
        $check(in_array($r->status, [200,503], true), 'readiness HTTP endpoint returns actual result');
        $check(!str_contains($r->body, 'password') && !str_contains($r->body, 'host'), 'readiness response contains no credentials');
        $r = $app->handle(new HttpRequest('GET','/api/v1/metrics', ['Accept'=>'text/plain']));
        $check($r->status === 401, 'metrics requires bearer credential');
        $r = $app->handle(new HttpRequest('GET','/api/v1/metrics', ['Authorization'=>'Bearer wrong', 'Accept'=>'text/plain']));
        $check($r->status === 401, 'metrics rejects invalid token');
        $r = $app->handle(new HttpRequest('GET','/api/v1/metrics', ['Authorization'=>'Bearer ' . str_repeat('A',48), 'Accept'=>'text/plain']));
        $check($r->status === 200, 'authorized metrics route responds');
        $check(str_starts_with($r->headers['Content-Type'] ?? '', 'text/plain'), 'Prometheus content type');
        $check(str_contains($r->body,'cajeer_dependency_up{dependency="database"}'), 'actual dependency metrics');
        $check(!str_contains($r->body, str_repeat('A',48)), 'scrape token not leaked');
        $check($app->handle(new HttpRequest('POST','/api/v1/ready'))->status === 405, 'readiness method restricted');
        $check($app->handle(new HttpRequest('GET','/api/v1/health'))->status === 200, 'previous runtime health preserved');
    } finally { $app->terminate(); }
    echo 'PASS: ' . $assertions . " observability checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    foreach (new FilesystemIterator($path) as $file) unlink($file->getPathname());
    rmdir($path);
}
