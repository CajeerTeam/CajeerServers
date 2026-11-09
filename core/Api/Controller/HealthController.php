<?php
declare(strict_types=1);

namespace Cajeer\Servers\Api\Controller;

use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Runtime\RuntimeDiagnostics;

/** Runtime readiness only. Does not claim database, queues or external connectors are healthy. */
final class HealthController
{
    public function __construct(private readonly RuntimeDiagnostics $diagnostics) {}

    public function __invoke(HttpRequest $request): HttpResponse
    {
        $ready = $this->diagnostics->run()['ok'];
        return HttpResponse::json([
            'status' => $ready ? 'ready' : 'not_ready',
            'service' => 'cajeerservers',
            'scope' => 'runtime',
        ], $ready ? 200 : 503);
    }
}
