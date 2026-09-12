<?php
namespace Cajeer\Servers\Security;

/** HMAC signed payload verifier. */
final class SignedPayload
{
    public function name(): string
    {
        return 'SignedPayload';
    }
}
