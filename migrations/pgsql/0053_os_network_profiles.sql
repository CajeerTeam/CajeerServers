-- Non-secret per-target trust. Existing targets retain legacy trust until enrolled.
ALTER TABLE connector_targets ADD COLUMN os_network_profile jsonb;
ALTER TABLE connector_targets ADD CONSTRAINT connector_os_network_profile_scope CHECK (
    os_network_profile IS NULL OR (provider IN ('ssh','winrm','snmp') AND jsonb_typeof(os_network_profile)='object')
);
