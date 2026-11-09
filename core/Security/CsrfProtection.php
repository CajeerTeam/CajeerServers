<?php
declare(strict_types=1);
namespace Cajeer\Servers\Security;

use Cajeer\Servers\Auth\TokenCodec;
use Cajeer\Servers\Http\Exception\HttpException;

/** Bound to server-side session; no double-submit cookie or untrusted Origin bypass. */
final class CsrfProtection
{
    public static function verify(string $storedHash, ?string $provided): void
    {
        if ($provided === null || $provided === '' || strlen($provided) > 256
            || strlen($storedHash) !== 64 || !hash_equals($storedHash, TokenCodec::digest($provided))) {
            throw new HttpException(403, 'Invalid CSRF token.', 'invalid_csrf');
        }
    }
}
