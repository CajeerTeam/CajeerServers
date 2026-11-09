<?php
declare(strict_types=1);

namespace Cajeer\Servers\Http\Middleware;

use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Security\SecurityHeaders;

/** Conservative headers for JSON-only public API, no permissive cross-origin defaults. */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(HttpRequest $request, callable $next): HttpResponse
    {
        return SecurityHeaders::apply($next($request), in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true));
    }
}
