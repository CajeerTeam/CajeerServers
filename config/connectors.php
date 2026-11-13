<?php
return [
    'write_actions' => getenv('CONNECTOR_WRITE_ACTIONS') === 'true',
    'exporter_extension_file' => getenv('DATA_EXPORTER_EXTENSION_FILE') ?: '',
    'extension_file' => getenv('CONNECTOR_EXTENSION_FILE') ?: '',
    // Operator-owned TLS CA and optional CIDR allowlist for outbound connector traffic.
    'pbs_ca_file' => getenv('PBS_CA_FILE') ?: '',
    'prometheus_ca_file' => getenv('PROMETHEUS_CA_FILE') ?: '',
    'proxmox_require_profile' => getenv('PROXMOX_REQUIRE_PROFILE') === 'true',
    'proxmox_ca_file' => getenv('PROXMOX_CA_FILE') ?: '',
    'vmmanager_require_profile' => getenv('VMMANAGER_REQUIRE_PROFILE') === 'true',
    'vmmanager_ca_file' => getenv('VMMANAGER_CA_FILE') ?: '',
    'python' => getenv('CONNECTOR_PYTHON') ?: '/usr/bin/python3',
    'ssh_known_hosts' => getenv('SSH_KNOWN_HOSTS_FILE') ?: '',
    'winrm_ca_file' => getenv('WINRM_CA_FILE') ?: '',
    'snmp_allow_v2c' => getenv('CONNECTOR_SNMP_ALLOW_V2C') === 'true',
    'allowed_networks' => array_values(array_filter(array_map('trim',explode(',',getenv('CONNECTOR_ALLOWED_NETWORKS') ?: '')),static fn(string $v):bool=>$v!=='')),
];
