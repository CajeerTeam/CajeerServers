<?php
return [
    'enabled' => getenv('SCRIPT_EXECUTION_ENABLED') === 'true',
    'registry_enabled' => true, 'signed_scripts' => true, 'dry_run' => true,
    'rootfs' => getenv('SCRIPT_SANDBOX_ROOTFS') ?: '',
    'history_retention_days' => 90,
];
