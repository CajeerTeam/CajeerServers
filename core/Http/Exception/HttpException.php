<?php
declare(strict_types=1);

namespace Cajeer\Servers\Http\Exception;

use RuntimeException;

/** Expected client/protocol error. No stack traces are returned to callers. */
class HttpException extends RuntimeException
{
    /** @param array<string,string> $headers @param list<array{field:string,message:string}> $errors */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly string $errorCode = 'bad_request',
        public readonly array $headers = [],
        public readonly array $errors = [],
    ) {
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException('HTTP exception status must be 4xx/5xx.');
        }
        parent::__construct($message);
    }
}
