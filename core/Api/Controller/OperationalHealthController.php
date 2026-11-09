<?php
declare(strict_types=1);

namespace Cajeer\Servers\Api\Controller;

use Cajeer\Servers\Observability\HealthCheck;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;

final class OperationalHealthController
{
    public function __construct(private readonly HealthCheck $health) {}

    public function live(HttpRequest $request): HttpResponse
    {
        return HttpResponse::json($this->health->live());
    }

    public function ready(HttpRequest $request): HttpResponse
    {
        $ready = $this->health->ready();
        return HttpResponse::json($ready, $ready['status'] === 'ready' ? 200 : 503);
    }
}
