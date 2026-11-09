<?php
declare(strict_types=1);

use Cajeer\Servers\Config\EnvironmentLoader;

return [
    'health_checks' => true,
    'system_report' => true,
    'metrics' => 'prometheus',
    'tracing' => 'opentelemetry',
    'prometheus_enabled' => env('PROMETHEUS_ENABLED', false) === true,
    'prometheus_token' => EnvironmentLoader::getString('PROMETHEUS_SCRAPE_TOKEN', ''),
    'otel_enabled' => env('OTEL_ENABLED', false) === true,
    'otel_endpoint' => EnvironmentLoader::getString('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://127.0.0.1:4318'),
    'otel_sample_ratio' => (float) env('OTEL_SAMPLE_RATIO', 0.1),
];
