<?php
return [
    // Operator-owned TLS CA and optional CIDR allowlist for outbound connector traffic.
    'proxmox_ca_file' => getenv('PROXMOX_CA_FILE') ?: '',
    'allowed_networks' => array_values(array_filter(array_map('trim',explode(',',getenv('CONNECTOR_ALLOWED_NETWORKS') ?: '')),static fn(string $v):bool=>$v!=='')),
];
