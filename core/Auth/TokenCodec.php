<?php
declare(strict_types=1);

namespace Cajeer\Servers\Auth;

/** Unpredictable opaque credentials. The database never stores a raw secret. */
final class TokenCodec
{
    /** @return array{id:string,credential:string,hash:string} */
    public static function issue(string $prefix): array
    {
        if (!in_array($prefix, ['cs1', 'at1'], true)) {
            throw new \InvalidArgumentException('Unsupported credential kind.');
        }
        $hex = bin2hex(random_bytes(16));
        $hex[12] = '4';
        $hex[16] = dechex((hexdec($hex[16]) & 3) | 8);
        $id = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20);
        $credential = $prefix . '.' . $id . '.' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return ['id' => $id, 'credential' => $credential, 'hash' => self::digest($credential)];
    }

    public static function digest(string $credential): string
    {
        return hash('sha256', $credential);
    }

    public static function parse(string $credential, string $kind): ?string
    {
        if (!in_array($kind, ['cs1', 'at1'], true) || strlen($credential) !== 84) {
            return null;
        }
        if (preg_match('/^' . $kind . '\.([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\.[A-Za-z0-9_-]{43}$/D', $credential, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    public static function validHash(string $expected, string $credential): bool
    {
        return strlen($expected) === 64 && hash_equals($expected, self::digest($credential));
    }
}
