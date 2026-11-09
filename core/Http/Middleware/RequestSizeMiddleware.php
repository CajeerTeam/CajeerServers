<?php
declare(strict_types=1);

namespace Cajeer\Servers\Http\Middleware;

use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;

final class RequestSizeMiddleware implements MiddlewareInterface
{
    public function process(HttpRequest $request, callable $next): HttpResponse
    {
        $length = $request->header('Content-Length');
        if ($length !== null && (!ctype_digit($length) || (float) $length > HttpRequest::MAX_BODY_BYTES)) {
            throw new HttpException(413, 'Request body too large or invalid Content-Length.', 'payload_too_large');
        }
        if ($request->bodyTruncated || strlen($request->body) > HttpRequest::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Request body too large.', 'payload_too_large');
        }
        return $next($request);
    }
}
