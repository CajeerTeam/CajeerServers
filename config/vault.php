<?php
// Vault master-key configuration is read solely by VaultMasterKey: VAULT_MASTER_KEY_FILE
// (0600) or VAULT_MASTER_KEY. Never reuse APP_KEY or the legacy VAULT_KEY.
return [
    'driver' => 'pgsql',
    'format' => 'cjev1',
    'encryption' => 'xchacha20-poly1305',
    'rotation_validation_ttl_seconds' => 600,
];
