<?php
namespace Cajeer\Servers\Api\Controller;

final class ActionController
{
    public function dispatch(): array
    {
        return ['accepted' => true, 'requires_approval' => true];
    }
}
