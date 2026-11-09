<?php
declare(strict_types=1);

namespace Cajeer\Servers\Observability;

use Cajeer\Servers\Runtime\RuntimeDiagnostics;

/** Liveness never depends on Redis/PostgreSQL; readiness does. */
final class HealthCheck
{
    public function __construct(private readonly RuntimeDiagnostics $runtime, private readonly Diagnostics $diagnostics) {}

    /** @return array{status:string,service:string,scope:string} */
    public function live(): array
    {
        return ['status' => 'alive', 'service' => 'cajeerservers', 'scope' => 'process'];
    }

    /** @return array{status:string,service:string,scope:string} */
    public function ready(): array
    {
        $result = $this->diagnostics->run();
        return ['status' => $result['status'], 'service' => 'cajeerservers', 'scope' => 'dependencies'];
    }
}
