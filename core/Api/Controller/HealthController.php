<?php
namespace Cajeer\Servers\Api\Controller;

final class HealthController
{
    public function __invoke(): array
    {
        return ['status' => 'ok', 'service' => 'cajeerservers'];
    }
}
