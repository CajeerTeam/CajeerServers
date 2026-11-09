<?php
declare(strict_types=1);
namespace Cajeer\Servers\Security;

/** Signed raw-body webhook verification, with bounded timestamps and replay digest. */
final class SignedPayload
{
    public static function signature(string $body, string $secret, int $timestamp): string
    {
        if (strlen($secret) < 32) throw new \InvalidArgumentException('Webhook secret is too short.');
        return 'sha256=' . hash_hmac('sha256', 'v1.' . $timestamp . '.' . $body, $secret);
    }

    /** @return string replay digest; caller MUST enforce DB uniqueness before accepting. */
    public static function verify(string $body, string $secret, string $timestamp, string $signature, ?int $now = null): string
    {
        if (strlen($body) > 1048576 || !preg_match('/^[1-9][0-9]{9,12}$/D', $timestamp)
            || !preg_match('/^sha256=[0-9a-f]{64}$/D', $signature)) {
            throw new \InvalidArgumentException('Malformed webhook signature.');
        }
        $now ??= time();
        if (abs($now - (int) $timestamp) > 300) throw new \InvalidArgumentException('Webhook timestamp outside allowed window.');
        if (!hash_equals(self::signature($body, $secret, (int) $timestamp), $signature)) {
            throw new \InvalidArgumentException('Invalid webhook signature.');
        }
        return hash('sha256', $timestamp . "\0" . $signature);
    }
}
