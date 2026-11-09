<?php
declare(strict_types=1);

namespace Cajeer\Servers\Runtime;

/** Immutable response with safe default headers and an optional Symfony HttpFoundation emitter. */
final readonly class HttpResponse
{
    /** @param array<string,string> $headers */
    public function __construct(public int $status, public string $body, public array $headers = [])
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('Invalid HTTP status.');
        }
    }

    /** @param array<string,mixed> $payload @param array<string,string> $headers */
    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        return new self($status, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            ...$headers,
        ]);
    }

    /** @param array<string,mixed> $payload @param array<string,string> $headers */
    public static function problem(array $payload, int $status, array $headers = []): self
    {
        $response = self::json($payload, $status, $headers);
        return $response->withHeader('Content-Type', 'application/problem+json; charset=utf-8');
    }

    public function withHeader(string $name, string $value): self
    {
        if (!preg_match('/^[a-zA-Z0-9!#$%&\'*+.^_`|~-]+$/D', $name) || strpbrk($value, "\r\n") !== false) {
            throw new \InvalidArgumentException('Invalid response header.');
        }
        $headers = $this->headers;
        foreach (array_keys($headers) as $header) {
            if (strcasecmp($header, $name) === 0) {
                unset($headers[$header]);
            }
        }
        $headers[$name] = $value;
        return new self($this->status, $this->body, $headers);
    }

    public function send(bool $head = false): void
    {
        $body = $head ? '' : $this->body;
        if (class_exists(\Symfony\Component\HttpFoundation\Response::class)) {
            (new \Symfony\Component\HttpFoundation\Response($body, $this->status, $this->headers))->send();
            return;
        }
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        if (!$head) {
            echo $body;
        }
    }
}
