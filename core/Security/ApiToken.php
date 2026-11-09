<?php
declare(strict_types=1);
namespace Cajeer\Servers\Security;

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Http\Exception\HttpException;

/** Runtime token scope policy shared by issuer and bearer verifier. */
final class ApiToken
{
    /** @param array<mixed> $scopes @return list<string> */
    public static function validateScopes(array $scopes): array
    {
        if (!array_is_list($scopes) || count($scopes) < 1 || count($scopes) > count(AuthenticationService::TOKEN_SCOPES)
            || count(array_filter($scopes, 'is_string')) !== count($scopes)
            || count(array_unique($scopes)) !== count($scopes)
            || array_diff($scopes, AuthenticationService::TOKEN_SCOPES)) {
            throw new HttpException(422, 'Invalid token scopes.', 'validation_failed');
        }
        return $scopes;
    }

    /** @param list<string> $granted @param list<string> $required */
    public static function authorize(array $granted, array $required): void
    {
        foreach ($required as $scope) {
            if (!in_array($scope, $granted, true)) throw new HttpException(403, 'API token scope is insufficient.', 'insufficient_scope');
        }
    }
}
