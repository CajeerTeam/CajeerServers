<?php
namespace Cajeer\Servers\Api\Controller;

final class ConnectorController
{
    public function index(): array
    {
        return ['connectors' => ['proxmox', 'vmmanager', 'linux', 'windows', 'router']];
    }
}
