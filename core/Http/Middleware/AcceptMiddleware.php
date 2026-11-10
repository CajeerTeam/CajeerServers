<?php
declare(strict_types=1);

namespace Cajeer\Servers\Http\Middleware;

use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;

/** JSON content negotiation with explicit media-range precedence and q=0 handling. */
final class AcceptMiddleware implements MiddlewareInterface
{
    public function process(HttpRequest $request, callable $next): HttpResponse
    {
        if (in_array($request->path, ['/install', '/api/v1/metrics'], true)) return $next($request);
        $requested = ($request->path === '/api/v1/inventory/exports' && ($request->query['format'] ?? 'csv') === 'csv')
            ? 'text/csv' : 'application/json';
        if ($request->method !== 'OPTIONS' && !self::acceptsMedia($request->header('Accept', '*/*'), $requested)) {
            throw new HttpException(406, 'Requested representation is not acceptable.', 'not_acceptable');
        }
        return $next($request);
    }

    private static function acceptsMedia(string $accept, string $type): bool
    {
        $ranges = [];
        foreach (explode(',', strtolower($accept)) as $part) {
            $parts = array_map('trim', explode(';', $part));
            $media = array_shift($parts);
            $quality = 1.0;
            foreach ($parts as $parameter) {
                if (str_starts_with($parameter, 'q=')) {
                    $q = substr($parameter, 2);
                    $quality = preg_match('/^(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/D', $q) ? (float) $q : 0.0;
                }
            }
            $ranges[] = [$media, $quality];
        }
        foreach ([$type] as $type) {
            $best = -1;
            $quality = 0.0;
            foreach ($ranges as [$media, $q]) {
                $specificity = match (true) {
                    $media === $type => 3,
                    $media === 'application/*+json' && str_ends_with($type, '+json') => 2,
                    $media === 'text/*' && str_starts_with($type, 'text/') => 1,
                    $media === 'application/*' => 1,
                    $media === '*/*' => 0,
                    default => -1,
                };
                if ($specificity > $best) {
                    $best = $specificity;
                    $quality = $q;
                } elseif ($specificity === $best) {
                    $quality = max($quality, $q);
                }
            }
            if ($best >= 0 && $quality > 0) return true;
        }
        return false;
    }
}
