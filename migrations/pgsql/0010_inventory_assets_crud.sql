-- Versioned, reversible asset soft-delete. Existing IDs and metadata remain unchanged.
ALTER TABLE inventory_assets ADD COLUMN version bigint NOT NULL DEFAULT 1;
ALTER TABLE inventory_assets ADD COLUMN deleted_at timestamptz;
ALTER TABLE inventory_assets ADD CONSTRAINT inventory_assets_version_positive CHECK (version > 0) NOT VALID;
CREATE INDEX inventory_assets_active_workspace_idx ON inventory_assets(workspace_id,created_at DESC,id DESC) WHERE deleted_at IS NULL;
CREATE INDEX inventory_assets_deleted_workspace_idx ON inventory_assets(workspace_id,deleted_at DESC,id DESC) WHERE deleted_at IS NOT NULL;
-- Covers all updates, including legacy workspace ownership operations. The row lock
-- and version increment are atomic in PostgreSQL.
CREATE FUNCTION cajeer_inventory_bump_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    NEW.version := OLD.version + 1;
    NEW.updated_at := clock_timestamp();
    RETURN NEW;
END;
$$;
CREATE TRIGGER inventory_asset_bump_version BEFORE UPDATE ON inventory_assets
FOR EACH ROW EXECUTE FUNCTION cajeer_inventory_bump_version();
INSERT INTO permissions(scope,description) VALUES
 ('inventory.assets.read','Read assets in authorized workspaces'),
 ('inventory.assets.create','Create manual assets in owned or managed workspaces'),
 ('inventory.assets.update','Edit manual asset metadata in owned or managed workspaces'),
 ('inventory.assets.delete','Soft-delete and restore manual assets')
ON CONFLICT (scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug='administrator' AND p.scope IN ('inventory.assets.read','inventory.assets.create','inventory.assets.update','inventory.assets.delete')
ON CONFLICT DO NOTHING;
