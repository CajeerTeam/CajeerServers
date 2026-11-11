<?php
return [
    'registry' => true,
    // Root-operated read-only Proxmox API TLS trust store; never disable verification.
    'proxmox_ca_file' => getenv('PROXMOX_CA_FILE') ?: '',
    'built_in' => ['proxmox', 'vmmanager', 'linux', 'windows', 'router'],
    'capabilities' => ['polling', 'webhooks', 'sync', 'actions', 'metrics', 'scripts'],
];
