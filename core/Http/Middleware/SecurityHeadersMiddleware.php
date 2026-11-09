<?php
declare(strict_types=1);

namespace Cajeer\Servers\Http\Middleware;

use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;

/** Conservative headers for JSON-only public API, no permissive cross-origin defaults. */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(HttpRequest $request, callable $next): HttpResponse
    {
        $response = $next($request);
        foreach ([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => str_starts_with($response->headers['Content-Type'] ?? '', 'text/html')
                ? "default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
                : "default-src 'none'; frame-ancestors 'none'",
            'Cache-Control' => 'no-store',
        ] as $header => $value) {
            $response = $response->withHeader($header, $value);
        }
        return $response;
    }
}
