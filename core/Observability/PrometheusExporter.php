<?php
namespace Cajeer\Servers\Observability;

/** Prometheus metrics exporter. */
final class PrometheusExporter
{
    public function name(): string
    {
        return 'PrometheusExporter';
    }
}
