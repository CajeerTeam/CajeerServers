<?php
declare(strict_types=1);

namespace Cajeer\Servers\Runtime;

use Cajeer\Servers\Http\Exception\HttpException;

/** Immutable HTTP request. Works with Symfony HttpFoundation if installed, and without vendor for diagnostics. */
final readonly class HttpRequest
{
    public const MAX_BODY_BYTES = 1048576;

    /** @param array<string,string> $headers @param array<string,mixed> $query @param array<string,mixed> $attributes */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers = [],
        public string $body = '',
        public array $query = [],
        public array $attributes = [],
        public bool $bodyTruncated = false,
    ) {
    }

    public function header(string $name, ?string $default = null): ?string
    {
        foreach ($this->headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }
        return $default;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        return new self($this->method, $this->path, $this->headers, $this->body, $this->query, [...$this->attributes, $key => $value], $this->bodyTruncated);
    }

    public static function fromGlobals(int $maxBytes = self::MAX_BODY_BYTES): self
    {
        if (class_exists(\Symfony\Component\HttpFoundation\Request::class)) {
            return self::fromSymfony(\Symfony\Component\HttpFoundation\Request::createFromGlobals(), $maxBytes);
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[str_replace('_', '-', $key)] = $value;
            }
        }
        $length = $headers['CONTENT-LENGTH'] ?? '0';
        $oversized = ctype_digit($length) && (float) $length > $maxBytes;
        $body = $oversized ? '' : file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($body === false) {
            $body = '';
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?: ''), $query);
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalizePath((string) (parse_url($uri, PHP_URL_PATH) ?: '/')),
            $headers,
            $body,
            $query,
            [],
            $oversized || strlen($body) > $maxBytes,
        );
    }

    public static function fromSymfony(\Symfony\Component\HttpFoundation\Request $request, int $maxBytes = self::MAX_BODY_BYTES): self
    {
        $headers = [];
        foreach ($request->headers->all() as $key => $values) {
            $headers[$key] = implode(', ', $values);
        }
        $length = $request->headers->get('Content-Length', '0');
        $oversized = ctype_digit($length) && (float) $length > $maxBytes;
        $body = $oversized ? '' : $request->getContent();
        return new self($request->getMethod(), self::normalizePath($request->getPathInfo()), $headers, $body, $request->query->all(), [], $oversized || strlen($body) > $maxBytes);
    }

    public static function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/' || preg_match('/%(?![0-9a-fA-F]{2})/', $path) || preg_match('/%(?:2f|5c|00)/i', $path)) {
            throw new HttpException(400, 'Invalid request path.', 'invalid_path');
        }
        $decoded = rawurldecode($path);
        if (!preg_match('//u', $decoded) || preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded) || str_contains($decoded, '//')) {
            throw new HttpException(400, 'Invalid request path.', 'invalid_path');
        }
        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new HttpException(400, 'Invalid request path.', 'invalid_path');
            }
        }
        return $decoded;
    }
}
