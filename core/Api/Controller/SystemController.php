<?php
declare(strict_types=1);

namespace Cajeer\Servers\Api\Controller;

use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;

/** Public version metadata only: no host paths, PHP configuration or credentials. */
final class SystemController
{
    public function __construct(private readonly Application $app) {}

    public function index(HttpRequest $request): HttpResponse
    {
        return HttpResponse::json([
            'name' => $this->app->name(),
            'version' => $this->app->version(),
            'api_version' => 'v1',
            'type' => 'api-first web control shell',
        ]);
    }
}
