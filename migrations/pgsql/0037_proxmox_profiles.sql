-- Optional hardening of existing targets; no credentials or remote response bodies are stored here.
ALTER TABLE connector_targets ADD COLUMN proxmox_profile jsonb;
ALTER TABLE connector_targets ADD CONSTRAINT connector_proxmox_profile_scope CHECK (
    proxmox_profile IS NULL OR (provider = 'proxmox' AND jsonb_typeof(proxmox_profile) = 'object')
);
