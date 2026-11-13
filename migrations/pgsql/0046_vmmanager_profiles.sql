-- Non-secret VMmanager trust configuration; existing targets remain compatible.
ALTER TABLE connector_targets ADD COLUMN vmmanager_profile jsonb;
ALTER TABLE connector_targets ADD CONSTRAINT connector_vmmanager_profile_scope CHECK (
    vmmanager_profile IS NULL OR (provider='vmmanager' AND jsonb_typeof(vmmanager_profile)='object')
);
