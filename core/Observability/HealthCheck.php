<?php
namespace Cajeer\Servers\Observability;

/** Health check contract. */
final class HealthCheck
{
    public function name(): string
    {
        return 'HealthCheck';
    }
}
