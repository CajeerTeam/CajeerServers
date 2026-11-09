<?php
declare(strict_types=1);

namespace Cajeer\Servers\Http\Middleware;

use Cajeer\Servers\Logging\JsonLogger;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Throwable;

final class AccessLogMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly JsonLogger $logger) {}

    public function process(HttpRequest $request, callable $next): HttpResponse
    {
        $start = hrtime(true);
        try {
            $response = $next($request);
            return $response;
        } catch (HttpException $error) {
            $failureStatus = $error->status;
            throw $error;
        } finally {
            try {
                $this->logger->info('HTTP request', [
                    'request_id' => $request->attribute('request_id'),
                    'method' => $request->method,
                    'path' => $request->path,
                    'duration_ms' => round((hrtime(true) - $start) / 1_000_000, 3),
                    'status' => isset($response) ? $response->status : ($failureStatus ?? 500),
                ]);
            } catch (Throwable) {
                error_log('CajeerServers: access log write failed.');
            }
        }
    }
}
