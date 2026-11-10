-- 0.3.0 CMDB stability: manual-field provenance and protection for soft-deleted IPAM owners.
-- Append-only migration: no existing Inventory or IPAM UUIDs are changed or deleted.

-- A manual asset created after migration 0014 previously lacked field provenance.
-- This backfill preserves all existing explicit source/manual overrides.
INSERT INTO inventory_field_provenance(asset_id, field, mode, source, actor_id)
SELECT a.id, fields.field, 'manual', 'manual', NULL
FROM inventory_assets a
CROSS JOIN (VALUES ('name'), ('status'), ('os_family'), ('ip_addresses'), ('tags'), ('environment')) fields(field)
WHERE a.source = 'manual'
ON CONFLICT (asset_id, field) DO NOTHING;

-- Always write provenance for manual asset creation and changed manual fields,
-- including application and privileged direct-SQL updates. Imported assets
-- keep the reconciliation engine as their provenance authority.
CREATE FUNCTION cajeer_cmdb_manual_provenance() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
  actor uuid;
BEGIN
  IF NEW.source <> 'manual' THEN RETURN NULL; END IF;
  actor := NULLIF(current_setting('cajeer.actor_id', true), '')::uuid;
  IF TG_OP = 'INSERT' THEN
    INSERT INTO inventory_field_provenance(asset_id, field, mode, source, actor_id)
    SELECT NEW.id, field, 'manual', 'manual', actor
    FROM (VALUES ('name'), ('status'), ('os_family'), ('ip_addresses'), ('tags'), ('environment')) f(field)
    WHERE TRUE
    ON CONFLICT(asset_id, field) DO NOTHING;
  ELSE
    INSERT INTO inventory_field_provenance(asset_id, field, mode, source, actor_id)
    SELECT NEW.id, f.field, 'manual', 'manual', actor
    FROM (VALUES
      ('name', OLD.name IS DISTINCT FROM NEW.name),
      ('status', OLD.status IS DISTINCT FROM NEW.status),
      ('os_family', OLD.os_family IS DISTINCT FROM NEW.os_family),
      ('ip_addresses', OLD.ip_addresses IS DISTINCT FROM NEW.ip_addresses),
      ('tags', OLD.tags IS DISTINCT FROM NEW.tags),
      ('environment', OLD.environment IS DISTINCT FROM NEW.environment)
    ) f(field, changed)
    WHERE f.changed
    ON CONFLICT(asset_id, field) DO UPDATE SET mode=EXCLUDED.mode,
      source=EXCLUDED.source, actor_id=EXCLUDED.actor_id,
      observed_at=NULL, updated_at=clock_timestamp();
  END IF;
  RETURN NULL;
END;
$$;
CREATE TRIGGER inventory_manual_provenance AFTER INSERT OR UPDATE ON inventory_assets
FOR EACH ROW EXECUTE FUNCTION cajeer_cmdb_manual_provenance();

-- Existing triggers forbid relations to deleted assets; IPAM interface owners
-- must have the same lifecycle protection. Check direct SQL as well as the API.
CREATE FUNCTION cajeer_cmdb_active_interface_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
    PERFORM 1 FROM workspaces WHERE id=OLD.workspace_id FOR UPDATE;
    IF EXISTS (SELECT 1 FROM inventory_interfaces i WHERE i.asset_id=OLD.id) THEN
      RAISE EXCEPTION 'Detach IPAM interfaces before soft-deleting this asset'
        USING ERRCODE='23514';
    END IF;
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER inventory_cmdb_active_interface_guard BEFORE UPDATE OF deleted_at ON inventory_assets
FOR EACH ROW EXECUTE FUNCTION cajeer_cmdb_active_interface_guard();
