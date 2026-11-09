<?php
declare(strict_types=1);

namespace Cajeer\Servers\Http;

use Cajeer\Servers\Runtime\HttpResponse;

/** Compatibility entrypoint; use HttpResponse inside the real HTTP kernel. */
final class JsonResponse
{
    /** @param array<string,mixed> $payload */
    public static function send(array $payload, int $status = 200): void
    {
        HttpResponse::json($payload, $status)->send();
    }
}
