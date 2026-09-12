<?php
namespace Cajeer\Servers\Action;

/** Dangerous action guard. */
final class DestructiveActionGuard
{
    public function name(): string
    {
        return 'DestructiveActionGuard';
    }
}
