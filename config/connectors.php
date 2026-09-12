<?php
return [
    'registry' => true,
    'built_in' => ['proxmox', 'vmmanager', 'linux', 'windows', 'router'],
    'capabilities' => ['polling', 'webhooks', 'sync', 'actions', 'metrics', 'scripts'],
];
