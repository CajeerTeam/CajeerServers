<?php
declare(strict_types=1);

namespace Cajeer\Servers\Observability;

use RuntimeException;

/** Persistent, bounded, per-instance HTTP counters (no unbounded URL/tenant labels). */
final class PrometheusExporter
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'OTHER'];
    private const BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    public function __construct(private readonly string $path) {}

    public function observe(string $method, int $status, float $seconds): void
    {
        $method = in_array(strtoupper($method), self::METHODS, true) ? strtoupper($method) : 'OTHER';
        $code = (string) max(1, min(5, intdiv($status, 100))) . 'xx';
        $seconds = max(0.0, min(3600.0, $seconds));
        $this->withStore(function (array &$data) use ($method, $code, $seconds): void {
            $key = "$method:$code";
            $row = $data['http'][$key] ?? ['count' => 0, 'sum' => 0.0, 'buckets' => array_fill(0, count(self::BUCKETS), 0)];
            $row['count']++;
            $row['sum'] += $seconds;
            foreach (self::BUCKETS as $i => $bound) if ($seconds <= $bound) $row['buckets'][$i]++;
            $data['http'][$key] = $row;
        });
    }

    /** Bounded outcome counters for real queue handler attempts; job IDs and payloads are never labels. */
    public function observeJob(string $type, bool $success, float $seconds): void
    {
        $type = in_array($type, ['runtime.diagnostics', 'storage.probe', 'queue.prune'], true) ? $type : 'other';
        $status = $success ? 'completed' : 'failed';
        $seconds = max(0.0, min(3600.0, $seconds));
        $this->withStore(static function (array &$data) use ($type, $status, $seconds): void {
            $key = "$type:$status";
            $entry = $data['jobs'][$key] ?? ['count' => 0, 'duration_seconds' => 0.0];
            $entry['count']++;
            $entry['duration_seconds'] += $seconds;
            $data['jobs'][$key] = $entry;
        });
    }

    public function render(?array $dependencies = null): string
    {
        $data = $this->withStore(static function (array &$data): void {});
        $out = [
            '# HELP cajeer_http_requests_total HTTP requests handled by this application instance.',
            '# TYPE cajeer_http_requests_total counter',
            '# HELP cajeer_http_request_duration_seconds HTTP request duration distribution.',
            '# TYPE cajeer_http_request_duration_seconds histogram',
            '# HELP cajeer_metrics_started_timestamp_seconds Timestamp when the metrics store was initialized.',
            '# TYPE cajeer_metrics_started_timestamp_seconds gauge',
            'cajeer_metrics_started_timestamp_seconds ' . (int)$data['started'],
        ];
        ksort($data['http']);
        foreach ($data['http'] as $key => $row) {
            [$method, $code] = explode(':', $key, 2);
            if (!in_array($method, self::METHODS, true) || !preg_match('/^[1-5]xx$/D', $code)) continue;
            $labels = 'method="' . $method . '",status_class="' . $code . '"';
            $out[] = 'cajeer_http_requests_total{' . $labels . '} ' . (int)$row['count'];
            foreach (self::BUCKETS as $i => $bound) {
                $out[] = 'cajeer_http_request_duration_seconds_bucket{' . $labels . ',le="' . $bound . '"} ' . (int)$row['buckets'][$i];
            }
            $out[] = 'cajeer_http_request_duration_seconds_bucket{' . $labels . ',le="+Inf"} ' . (int)$row['count'];
            $out[] = 'cajeer_http_request_duration_seconds_sum{' . $labels . '} ' . sprintf('%.9f', (float)$row['sum']);
            $out[] = 'cajeer_http_request_duration_seconds_count{' . $labels . '} ' . (int)$row['count'];
        }
        $out[] = '# HELP cajeer_queue_jobs_processed_total Executed queue handler attempts.';
        $out[] = '# TYPE cajeer_queue_jobs_processed_total counter';
        $out[] = '# HELP cajeer_queue_job_duration_seconds_total Total handler execution duration.';
        $out[] = '# TYPE cajeer_queue_job_duration_seconds_total counter';
        foreach (($data['jobs'] ?? []) as $key => $row) {
            [$type, $status] = explode(':', $key, 2);
            if (!in_array($type, ['runtime.diagnostics','storage.probe','queue.prune','other'], true)
                || !in_array($status, ['completed','failed'], true)) continue;
            $labels = 'type="' . $type . '",outcome="' . $status . '"';
            $out[] = 'cajeer_queue_jobs_processed_total{' . $labels . '} ' . (int)$row['count'];
            $out[] = 'cajeer_queue_job_duration_seconds_total{' . $labels . '} ' . sprintf('%.9f',(float)$row['duration_seconds']);
        }
        if ($dependencies !== null) {
            $out[] = '# HELP cajeer_dependency_up Health of the configured dependency (1=available, 0=unavailable).';
            $out[] = '# TYPE cajeer_dependency_up gauge';
            foreach (['runtime', 'installation', 'database', 'redis', 'queue', 'scheduler', 'storage'] as $name) {
                if (!isset($dependencies['checks'][$name])) continue;
                $out[] = 'cajeer_dependency_up{dependency="' . $name . '"} '
                    . ($dependencies['checks'][$name]['ok'] ? '1' : '0');
            }
        }
        return implode("\n", $out) . "\n";
    }

    /** @return array{started:int,http:array<string,mixed>} */
    private function withStore(callable $callback): array
    {
        $directory = dirname($this->path);
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new RuntimeException('Metrics directory is not available.');
        }
        if (is_link($this->path)) throw new RuntimeException('Symlink metrics store denied.');
        $previous = umask(0077);
        try { $handle = @fopen($this->path, 'c+'); }
        finally { umask($previous); }
        if (!is_resource($handle)) throw new RuntimeException('Cannot open metrics store.');
        try {
            @chmod($this->path, 0600);
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Cannot lock metrics store.');
            $length = fstat($handle)['size'] ?? 0;
            if ($length > 131072) throw new RuntimeException('Metrics store exceeds size limit.');
            $contents = stream_get_contents($handle);
            $data = $contents !== '' ? json_decode($contents, true) : null;
            if ($contents !== '' && (!is_array($data) || !isset($data['started'], $data['http']) || !is_array($data['http']))) {
                throw new RuntimeException('Corrupted metrics store.');
            }
            $data ??= ['started' => time(), 'http' => []];
            $before = json_encode($data, JSON_THROW_ON_ERROR);
            $callback($data);
            $after = json_encode($data, JSON_THROW_ON_ERROR);
            if ($before !== $after || $contents === '') {
                rewind($handle);
                if (!ftruncate($handle, 0) || fwrite($handle, $after) !== strlen($after) || !fflush($handle)) {
                    throw new RuntimeException('Cannot persist metrics store.');
                }
            }
            return $data;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
