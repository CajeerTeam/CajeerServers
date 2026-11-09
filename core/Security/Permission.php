<?php
declare(strict_types=1);
namespace Cajeer\Servers\Security;

/** Explicit registry of permissions that may be delegated in version 0.1.6. */
final class Permission
{
    public static function assertKnown(string $scope): void
    {
        if (!in_array($scope, Rbac::PERMISSIONS, true)) {
            throw new \InvalidArgumentException('Unknown permission: ' . $scope);
        }
    }
}
