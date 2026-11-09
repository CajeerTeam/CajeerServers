-- Upgrade-safe guardrails for the existing inventory_assets table.
-- Keep the original 0001 checksum unchanged. Legacy rows are NEVER rewritten here.
-- NOT VALID avoids rejecting existing installations with dirty data during upgrade.
-- Operators audit legacy rows and explicitly validate checks after repairing them.
ALTER TABLE inventory_assets ADD CONSTRAINT inventory_assets_name_nonblank CHECK (name ~ '[^[:space:]]') NOT VALID;
ALTER TABLE inventory_assets ADD CONSTRAINT inventory_assets_type_nonblank CHECK (type ~ '[^[:space:]]') NOT VALID;
ALTER TABLE inventory_assets ADD CONSTRAINT inventory_assets_source_nonblank CHECK (source ~ '[^[:space:]]') NOT VALID;
ALTER TABLE inventory_assets ADD CONSTRAINT inventory_assets_status_nonblank CHECK (status ~ '[^[:space:]]') NOT VALID;
ALTER TABLE inventory_assets ADD CONSTRAINT inventory_assets_external_id_nonblank CHECK (external_id IS NULL OR external_id ~ '[^[:space:]]') NOT VALID;
ALTER TABLE inventory_assets ADD CONSTRAINT inventory_assets_ip_array CHECK (jsonb_typeof(ip_addresses) = 'array') NOT VALID;
ALTER TABLE inventory_assets ADD CONSTRAINT inventory_assets_tags_array CHECK (jsonb_typeof(tags) = 'array') NOT VALID;
ALTER TABLE inventory_assets ADD CONSTRAINT inventory_assets_attributes_object CHECK (jsonb_typeof(attributes) = 'object') NOT VALID;

-- Target existing 0.2.x read patterns; IPAM and workspace constraints follow in later releases.
CREATE INDEX inventory_assets_type_status_idx ON inventory_assets (type, status, id);
CREATE INDEX inventory_assets_source_lookup_idx ON inventory_assets (source, external_id) WHERE external_id IS NOT NULL;
CREATE INDEX inventory_assets_tags_gin_idx ON inventory_assets USING gin (tags);
CREATE INDEX inventory_assets_ips_gin_idx ON inventory_assets USING gin (ip_addresses);
