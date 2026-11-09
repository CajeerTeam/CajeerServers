<?php
declare(strict_types=1);

namespace Cajeer\Servers\Observability;

use RuntimeException;

/** Opt-in OTLP/HTTP JSON exporter. Never includes URLs, headers, cookies, tokens or request bodies. */
final class OpenTelemetryTracer
{
    public function __construct(
        private readonly bool $enabled,
        private readonly string $endpoint,
        private readonly string $serviceVersion,
        private readonly float $sampleRatio = 1.0,
    ) {
        if ($sampleRatio < 0 || $sampleRatio > 1) throw new RuntimeException('Invalid trace sampling ratio.');
        if ($enabled) self::validateEndpoint($endpoint);
    }

    public function recordHttp(string $method, int $status, float $durationSeconds): bool
    {
        if (!$this->enabled || $this->sampleRatio <= 0) return false;
        if (random_int(0, 999999) >= (int) round($this->sampleRatio * 1000000)) return false;
        $safeMethod = in_array(strtoupper($method), ['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'], true) ? strtoupper($method) : 'OTHER';
        $endNs = (int) (microtime(true) * 1_000_000_000);
        $startNs = $endNs - (int) round(max(0, $durationSeconds) * 1_000_000_000);
        $attributes = [
            ['key' => 'http.request.method', 'value' => ['stringValue' => $safeMethod]],
            ['key' => 'http.response.status_code', 'value' => ['intValue' => (string) $status]],
        ];
        $payload = ['resourceSpans' => [[
            'resource' => ['attributes' => [
                ['key' => 'service.name', 'value' => ['stringValue' => 'cajeerservers']],
                ['key' => 'service.version', 'value' => ['stringValue' => $this->serviceVersion]],
            ]],
            'scopeSpans' => [[
                'scope' => ['name' => 'cajeerservers.http', 'version' => $this->serviceVersion],
                'spans' => [[
                    'traceId' => bin2hex(random_bytes(16)),
                    'spanId' => bin2hex(random_bytes(8)),
                    'name' => 'HTTP ' . $safeMethod,
                    'kind' => 2,
                    'startTimeUnixNano' => (string) $startNs,
                    'endTimeUnixNano' => (string) $endNs,
                    'attributes' => $attributes,
                    'status' => ['code' => $status >= 500 ? 2 : 1],
                ]],
            ]],
        ]]];
        return $this->send($payload);
    }

    /** Queue spans are exported with bounded labels; no payload, job UUID or exception text. */
    public function recordJob(string $type, bool $success, float $durationSeconds): bool
    {
        if (!$this->enabled || $this->sampleRatio <= 0) return false;
        if (random_int(0, 999999) >= (int) round($this->sampleRatio * 1000000)) return false;
        $type = in_array($type, ['runtime.diagnostics', 'storage.probe', 'queue.prune'], true) ? $type : 'other';
        $endNs = (int) (microtime(true) * 1_000_000_000);
        $payload = ['resourceSpans' => [[
            'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'cajeerservers']]]],
            'scopeSpans' => [[
                'scope' => ['name' => 'cajeerservers.queue', 'version' => $this->serviceVersion],
                'spans' => [[
                    'traceId' => bin2hex(random_bytes(16)), 'spanId' => bin2hex(random_bytes(8)),
                    'name' => 'queue.process', 'kind' => 3,
                    'startTimeUnixNano' => (string) ($endNs - (int)(max(0.0, $durationSeconds) * 1_000_000_000)),
                    'endTimeUnixNano' => (string) $endNs,
                    'attributes' => [
                        ['key' => 'messaging.operation.name', 'value' => ['stringValue' => 'process']],
                        ['key' => 'messaging.destination.name', 'value' => ['stringValue' => $type]],
                    ],
                    'status' => ['code' => $success ? 1 : 2],
                ]],
            ]],
        ]]];
        return $this->send($payload);
    }

    private function send(array $payload): bool
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $json, 'timeout' => 0.35, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $result = @file_get_contents(self::traceUrl($this->endpoint), false, $context);
        $line = $http_response_header[0] ?? '';
        return $result !== false && preg_match('~^HTTP/\S+ 2\d\d(?:\s|$)~', $line) === 1;
    }

    private static function traceUrl(string $endpoint): string
    {
        return rtrim($endpoint, '/') . (str_ends_with(rtrim($endpoint, '/'), '/v1/traces') ? '' : '/v1/traces');
    }

    private static function validateEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['scheme'], ['https', 'http'], true)) {
            throw new RuntimeException('OTLP endpoint must be an absolute HTTPS URL.');
        }
        if ($parts['scheme'] === 'http' && !in_array($parts['host'], ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('Only loopback HTTP OTLP endpoints are allowed.');
        }
    }
}
