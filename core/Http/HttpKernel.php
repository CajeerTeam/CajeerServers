<?php
declare(strict_types=1);

namespace Cajeer\Servers\Http;

use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Http\Middleware\AcceptMiddleware;
use Cajeer\Servers\Http\Middleware\AccessLogMiddleware;
use Cajeer\Servers\Http\Middleware\MiddlewareInterface;
use Cajeer\Servers\Http\Middleware\RequestSizeMiddleware;
use Cajeer\Servers\Http\Middleware\SecurityHeadersMiddleware;
use Cajeer\Servers\Logging\JsonLogger;
use Cajeer\Servers\Security\SecurityHeaders;
use Cajeer\Servers\Runtime\ErrorHandler;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Throwable;

/** Front controller pipeline. The error boundary and security headers apply even to failed requests. */
final class HttpKernel
{
    /** @var list<MiddlewareInterface> */
    private array $middleware;

    public function __construct(
        private readonly Router $router,
        private readonly JsonLogger $logger,
        private readonly ErrorHandler $errors,
    ) {
        $this->middleware = [
            new AccessLogMiddleware($logger),
            new SecurityHeadersMiddleware(),
            new RequestSizeMiddleware(),
            new AcceptMiddleware(),
        ];
    }

    /** Register a real processable middleware in the global HTTP pipeline. */
    public function pipe(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $requestId = bin2hex(random_bytes(12));
        $request = $request->withAttribute('request_id', $requestId);
        $this->errors->mode('http', $requestId);
        $next = fn (HttpRequest $request): HttpResponse => $this->router->dispatch($request);
        foreach (array_reverse($this->middleware) as $middleware) {
            $inner = $next;
            $next = static fn (HttpRequest $request): HttpResponse => $middleware->process($request, $inner);
        }
        try {
            $response = $next($request);
        } catch (HttpException $exception) {
            $response = $this->problem($exception, $requestId);
        } catch (Throwable $error) {
            $this->logException($error, $requestId);
            $response = $this->problem(new HttpException(500, 'Internal Server Error', 'internal_error'), $requestId);
        }
        // Apply even on exception paths, including failures inside middleware.
        return $this->secure($response)->withHeader('X-Request-Id', $requestId);
    }

    /** HTTP protocol errors thrown during parsing before a Request exists. */
    public function errorResponse(HttpException $error): HttpResponse
    {
        $requestId = bin2hex(random_bytes(12));
        $this->errors->mode('http', $requestId);
        return $this->secure($this->problem($error, $requestId))->withHeader('X-Request-Id', $requestId);
    }

    private function problem(HttpException $error, string $requestId): HttpResponse
    {
        $payload = [
            'type' => 'about:blank',
            'title' => $error->getMessage(),
            'status' => $error->status,
            'code' => $error->errorCode,
            'request_id' => $requestId,
        ];
        if ($error->errors) $payload['errors'] = $error->errors;
        return HttpResponse::problem($payload, $error->status, $error->headers);
    }

    private function secure(HttpResponse $response): HttpResponse
    {
        return SecurityHeaders::apply($response, in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true));
    }

    private function logException(Throwable $error, string $requestId): void
    {
        try {
            $this->logger->error('HTTP handler failure', [
                'exception' => $error::class,
                'fingerprint' => hash('sha256', $error::class . ':' . $error->getMessage()),
                'request_id' => $requestId,
            ]);
        } catch (Throwable) {
            error_log('CajeerServers: HTTP exception could not be logged.');
        }
    }
}
