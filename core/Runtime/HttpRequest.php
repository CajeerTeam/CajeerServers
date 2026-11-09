<?php
declare(strict_types=1);

namespace Cajeer\Servers\Runtime;

/** Minimal immutable entrypoint request; full routing is a 0.1.2 milestone. */
final readonly class HttpRequest
{
    public function __construct(public string $method, public string $path)
    {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        return new self(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), is_string($path) && $path !== '' ? $path : '/');
    }
}
