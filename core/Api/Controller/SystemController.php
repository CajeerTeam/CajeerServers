<?php
namespace Cajeer\Servers\Api\Controller;

final class SystemController
{
    public function index(): array
    {
        return ['name' => 'CajeerServers', 'type' => 'web-resource'];
    }
}
