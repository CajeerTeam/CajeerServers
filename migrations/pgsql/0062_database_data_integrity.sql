-- PostgreSQL 17/18: enforce IPAM overlap under all isolation levels and preserve
-- provenance/import invariants. Existing asset IDs and historical revisions are untouched.
CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA public;
ALTER TABLE inventory_networks ADD CONSTRAINT inventory_networks_no_overlap
    EXCLUDE USING gist (workspace_id WITH =, cidr inet_ops WITH &&);

-- Missing rows from pre-0.9.4 clearOverride represented observed/unlocked fields.
-- Do not invent an observation timestamp or actor, or overwrite existing overrides.
INSERT INTO inventory_field_provenance(asset_id,field,mode,source)
SELECT a.id,f.field,CASE WHEN a.source='manual' THEN 'manual' ELSE 'observed' END,a.source
FROM inventory_assets a CROSS JOIN (VALUES ('name'),('status'),('os_family'),('ip_addresses'),('tags'),('environment')) f(field)
WHERE TRUE
ON CONFLICT(asset_id,field) DO NOTHING;

-- Old conflicts remain historical evidence, but cannot follow an asset into a new workspace.
UPDATE inventory_reconciliation_conflicts c SET resolved_at=clock_timestamp()
FROM inventory_assets a WHERE a.id=c.asset_id AND a.workspace_id<>c.workspace_id AND c.resolved_at IS NULL;
-- Reset only legacy cursors that demonstrably point at a former workspace.
DELETE FROM inventory_observation_state s USING inventory_assets a
WHERE s.asset_id=a.id AND EXISTS (SELECT 1 FROM inventory_observations o
 WHERE o.asset_id=s.asset_id AND o.source=s.source AND o.observed_at=s.last_observed_at AND o.workspace_id<>a.workspace_id AND o.outcome<>'stale')
AND NOT EXISTS (SELECT 1 FROM inventory_observations o
 WHERE o.asset_id=s.asset_id AND o.source=s.source AND o.observed_at=s.last_observed_at AND o.workspace_id=a.workspace_id AND o.outcome<>'stale');
CREATE FUNCTION cajeer_cmdb_transfer_conflicts() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.workspace_id IS DISTINCT FROM NEW.workspace_id THEN
        UPDATE inventory_reconciliation_conflicts SET resolved_at=clock_timestamp()
        WHERE asset_id=OLD.id AND resolved_at IS NULL;
        DELETE FROM inventory_observation_state WHERE asset_id=OLD.id;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER inventory_transfer_close_conflicts BEFORE UPDATE OF workspace_id ON inventory_assets
FOR EACH ROW EXECUTE FUNCTION cajeer_cmdb_transfer_conflicts();

-- Validate existing import batches instead of silently discarding incomplete or corrupt data.
ALTER TABLE inventory_import_batches ADD CONSTRAINT inventory_import_payload_count
    CHECK (jsonb_array_length(rows_payload)=row_count);
ALTER TABLE inventory_import_batches ADD CONSTRAINT inventory_import_terminal_count
    CHECK (status NOT IN ('completed','completed_with_errors') OR processed_rows=row_count);

-- Batch progress is a contiguous committed prefix, not a count of arbitrary receipts.
CREATE FUNCTION cajeer_import_progress_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE bid uuid; expected integer; actual bigint; last_row integer;
BEGIN
    IF TG_TABLE_NAME='inventory_import_batches' THEN
        bid := NEW.id;
    ELSIF TG_OP='DELETE' THEN
        bid := OLD.batch_id;
    ELSE
        bid := NEW.batch_id;
        IF TG_OP='UPDATE' AND OLD.batch_id IS DISTINCT FROM NEW.batch_id THEN
            RAISE EXCEPTION 'Cannot move import receipt to another batch' USING ERRCODE='23514';
        END IF;
    END IF;
    SELECT processed_rows INTO expected FROM inventory_import_batches WHERE id=bid FOR UPDATE;
    IF NOT FOUND THEN RETURN NULL; END IF;
    SELECT count(*),coalesce(max(row_number),0) INTO actual,last_row FROM inventory_import_results WHERE batch_id=bid;
    IF actual<>expected OR last_row<>expected THEN
        RAISE EXCEPTION 'Import receipts must match contiguous committed progress' USING ERRCODE='23514';
    END IF;
    RETURN NULL;
END;
$$;
-- Fail the migration for corrupt pre-existing progress. Operator must investigate/restore, not guess.
DO $$
BEGIN
 IF EXISTS (SELECT 1 FROM inventory_import_batches b LEFT JOIN LATERAL
   (SELECT count(*) AS n,coalesce(max(row_number),0) AS last_row FROM inventory_import_results r WHERE r.batch_id=b.id) r ON true
   WHERE b.processed_rows<>r.n OR b.processed_rows<>r.last_row) THEN
   RAISE EXCEPTION 'Existing import progress is inconsistent; inspect inventory:integrity before upgrade' USING ERRCODE='23514';
 END IF;
END;
$$;
CREATE CONSTRAINT TRIGGER inventory_import_batch_progress AFTER INSERT OR UPDATE ON inventory_import_batches
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION cajeer_import_progress_guard();
CREATE CONSTRAINT TRIGGER inventory_import_receipt_progress AFTER INSERT OR UPDATE OR DELETE ON inventory_import_results
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION cajeer_import_progress_guard();
