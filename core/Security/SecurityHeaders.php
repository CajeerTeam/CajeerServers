<?php
declare(strict_types=1);
namespace Cajeer\Servers\Security;

use Cajeer\Servers\Runtime\HttpResponse;

/** The same headers are applied to successful and exceptional HTTP responses. */
final class SecurityHeaders
{
    public static function apply(HttpResponse $response, bool $https): HttpResponse
    {
        $html = str_starts_with($response->headers['Content-Type'] ?? '', 'text/html');
        foreach ([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => $html
                ? "default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
                : "default-src 'none'; frame-ancestors 'none'",
            'Cache-Control' => 'no-store',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ] as $name => $value) $response = $response->withHeader($name, $value);
        if ($https) $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        return $response;
    }
}
