<?php
declare(strict_types=1);

namespace Cajeer\Servers\Runtime;

use JsonException;

/** HTTP boundary response that emits no body for HEAD and never caches diagnostics. */
final readonly class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(public int $status, public string $body, public array $headers = [])
    {
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return new self($status, $body, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            ...$headers,
        ]);
    }

    public function send(bool $head = false): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        if (!$head) {
            echo $this->body;
        }
    }
}
