<?php
declare(strict_types=1);

namespace Cajeer\Servers\Http\Middleware;

use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;

interface MiddlewareInterface
{
    /** @param callable(HttpRequest):HttpResponse $next */
    public function process(HttpRequest $request, callable $next): HttpResponse;
}
