<?php
declare(strict_types=1);

namespace Cajeer\Servers\Credentials;

use Cajeer\Servers\Http\Exception\HttpException;

/** Strict, allowlisted API input. Secret values are never included in validation errors. */
final class CredentialInput
{
    public static function uuid(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $value) !== 1) {
            throw new HttpException(422, 'Invalid credential or workspace identifier.', 'validation_failed');
        }
        return strtolower($value);
    }

    public static function name(mixed $value): string
    {
        if (!is_string($value) || !preg_match('//u', $value)) {
            throw new HttpException(422, 'Invalid credential name.', 'validation_failed');
        }
        $name = trim($value);
        if ($name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new HttpException(422, 'Invalid credential name.', 'validation_failed');
        }
        return $name;
    }

    public static function description(mixed $value): string
    {
        if (!is_string($value) || !preg_match('//u', $value) || strlen($value) > 1024 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new HttpException(422, 'Invalid credential description.', 'validation_failed');
        }
        return trim($value);
    }

    public static function type(mixed $value): string
    {
        return CredentialProfile::type($value);
    }

    public static function secret(mixed $value): string
    {
        if (!is_string($value) || strlen($value) < 1 || strlen($value) > 65536 || !preg_match('//u', $value)) {
            throw new HttpException(422, 'Invalid secret value or size.', 'validation_failed');
        }
        return $value;
    }

    public static function status(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['active', 'disabled'], true)) {
            throw new HttpException(422, 'Invalid credential status.', 'validation_failed');
        }
        return $value;
    }

    public static function etag(string $credentialId, int $version): string
    {
        return '"' . self::uuid($credentialId) . ':' . $version . '"';
    }

    public static function version(string $credentialId, ?string $header): int
    {
        if ($header === null) throw new HttpException(428, 'If-Match required.', 'precondition_required');
        if (!preg_match('/^"([a-f0-9-]{36}):([1-9][0-9]{0,15})"$/iD', $header, $match)
            || strtolower($match[1]) !== self::uuid($credentialId)) {
            throw new HttpException(400, 'Invalid If-Match.', 'invalid_precondition');
        }
        return (int) $match[2];
    }

    /** @param array<string,mixed> $input */
    public static function patch(array $input): array
    {
        if ($input === [] || array_diff(array_keys($input), ['name', 'description', 'status'])) {
            throw new HttpException(422, 'Invalid metadata patch.', 'validation_failed');
        }
        $result = [];
        foreach ($input as $key => $value) {
            $result[$key] = match ($key) {
                'name' => self::name($value),
                'description' => self::description($value),
                'status' => self::status($value),
            };
        }
        return $result;
    }
}
