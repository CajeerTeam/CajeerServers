-- 0.2.7: append-only CMDB asset history, observation idempotency and field provenance.
-- Existing asset IDs and legacy JSONB/IPAM data are not rewritten.
-- The old global source identity was incompatible with workspace isolation.
-- Migration 0009 already replaced the global identity with the workspace-scoped unique index.

CREATE TABLE inventory_asset_revisions (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    asset_id uuid NOT NULL REFERENCES inventory_assets(id) ON DELETE RESTRICT,
    workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
    asset_version bigint NOT NULL CHECK(asset_version>0),
    operation varchar(32) NOT NULL CHECK(operation IN ('baseline','insert','update','delete','restore','transfer')),
    origin varchar(32) NOT NULL CHECK(origin IN ('manual','observation','database','baseline')),
    actor_id uuid,
    request_id varchar(128),
    before_state jsonb NOT NULL DEFAULT '{}'::jsonb,
    after_state jsonb NOT NULL DEFAULT '{}'::jsonb,
    changes jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE(asset_id,asset_version)
);
CREATE INDEX inventory_asset_revisions_page_idx ON inventory_asset_revisions(workspace_id,asset_id,asset_version DESC);

-- Do NOT store arbitrary attributes or credentials in historical snapshots.
CREATE FUNCTION cajeer_inventory_history_snapshot(a inventory_assets) RETURNS jsonb
LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('id',(a).id,'workspace_id',(a).workspace_id,'type',(a).type,
     'source',(a).source,'external_id',(a).external_id,'name',(a).name,'status',(a).status,
     'os_family',(a).os_family,'ip_addresses',(a).ip_addresses,'tags',(a).tags,
     'environment',(a).environment,'owner_user_id',(a).owner_user_id,
     'deleted_at',(a).deleted_at,'attributes_redacted',true)
$$;

-- Previous revisions cannot be rewritten through application SQL.
CREATE FUNCTION cajeer_inventory_revisions_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Inventory history is append-only' USING ERRCODE='55000';
END;
$$;
CREATE TRIGGER inventory_history_immutable BEFORE UPDATE OR DELETE ON inventory_asset_revisions
FOR EACH ROW EXECUTE FUNCTION cajeer_inventory_revisions_immutable();
CREATE TRIGGER inventory_history_no_truncate BEFORE TRUNCATE ON inventory_asset_revisions
FOR EACH STATEMENT EXECUTE FUNCTION cajeer_inventory_revisions_immutable();

-- Backfill one baseline per existing asset; past states cannot be reconstructed.
INSERT INTO inventory_asset_revisions(asset_id,workspace_id,asset_version,operation,origin,after_state)
SELECT id,workspace_id,version,'baseline','baseline',cajeer_inventory_history_snapshot(a)
FROM inventory_assets a;

-- Trigger captures changes from API, workspace moves, catalog, imports and direct SQL.
-- Uses transaction-local application context, never persisted connection state.
CREATE FUNCTION cajeer_inventory_history_capture() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 old_state jsonb := '{}'::jsonb;
 new_state jsonb;
 delta jsonb;
 operation_name text;
 context_actor text;
 context_origin text;
 context_request text;
BEGIN
 new_state:=cajeer_inventory_history_snapshot(NEW);
 IF TG_OP='UPDATE' THEN old_state:=cajeer_inventory_history_snapshot(OLD); END IF;
 SELECT coalesce(jsonb_object_agg(k,jsonb_build_object('before',old_state->k,'after',new_state->k)),'{}'::jsonb)
 INTO delta FROM jsonb_object_keys(old_state || new_state) AS k
 WHERE old_state->k IS DISTINCT FROM new_state->k;
 IF TG_OP='UPDATE' AND OLD.attributes IS DISTINCT FROM NEW.attributes THEN
   delta:=delta || '{"attributes":{"redacted":true}}'::jsonb;
 END IF;
 operation_name:=CASE WHEN TG_OP='INSERT' THEN 'insert'
   WHEN OLD.workspace_id IS DISTINCT FROM NEW.workspace_id THEN 'transfer'
   WHEN OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN 'delete'
   WHEN OLD.deleted_at IS NOT NULL AND NEW.deleted_at IS NULL THEN 'restore'
   ELSE 'update' END;
 context_actor:=nullif(current_setting('cajeer.actor_id',true),'');
 context_origin:=nullif(current_setting('cajeer.change_origin',true),'');
 context_request:=nullif(current_setting('cajeer.request_id',true),'');
 IF context_actor IS NOT NULL AND context_actor !~* '^[a-f0-9-]{36}$' THEN context_actor:=NULL; END IF;
 IF context_origin NOT IN ('manual','observation','database') THEN context_origin:=NULL; END IF;
 INSERT INTO inventory_asset_revisions(asset_id,workspace_id,asset_version,operation,origin,actor_id,request_id,before_state,after_state,changes)
 VALUES(NEW.id,NEW.workspace_id,NEW.version,operation_name,
        COALESCE(context_origin,CASE WHEN NEW.source='manual' THEN 'manual' ELSE 'database' END),
        context_actor::uuid,left(context_request,128),old_state,new_state,delta);
 RETURN NULL;
END;
$$;
CREATE TRIGGER inventory_history_capture AFTER INSERT OR UPDATE ON inventory_assets
FOR EACH ROW EXECUTE FUNCTION cajeer_inventory_history_capture();

CREATE TABLE inventory_observations (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
    asset_id uuid NOT NULL REFERENCES inventory_assets(id) ON DELETE RESTRICT,
    actor_id uuid REFERENCES users(id) ON DELETE SET NULL,
    source varchar(120) NOT NULL CHECK(source='import'),
    external_id varchar(255) NOT NULL CHECK(length(external_id)>0),
    event_key varchar(120) NOT NULL CHECK(length(event_key)>0),
    payload_hash char(64) NOT NULL,
    observed_at timestamptz NOT NULL,
    received_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    outcome varchar(16) NOT NULL CHECK(outcome IN ('created','applied','unchanged','conflicted','stale')),
    applied_fields jsonb NOT NULL DEFAULT '[]'::jsonb,
    conflicted_fields jsonb NOT NULL DEFAULT '[]'::jsonb,
    UNIQUE(workspace_id,source,event_key)
);
CREATE INDEX inventory_observations_asset_idx ON inventory_observations(workspace_id,asset_id,received_at DESC);
CREATE TRIGGER inventory_observations_immutable BEFORE UPDATE OR DELETE ON inventory_observations
FOR EACH ROW EXECUTE FUNCTION cajeer_inventory_revisions_immutable();
CREATE TRIGGER inventory_observations_no_truncate BEFORE TRUNCATE ON inventory_observations
FOR EACH STATEMENT EXECUTE FUNCTION cajeer_inventory_revisions_immutable();

CREATE TABLE inventory_observation_state (
    asset_id uuid NOT NULL REFERENCES inventory_assets(id) ON DELETE RESTRICT,
    source varchar(120) NOT NULL,
    last_observed_at timestamptz NOT NULL,
    last_received_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY(asset_id,source)
);
CREATE TABLE inventory_field_provenance (
    asset_id uuid NOT NULL REFERENCES inventory_assets(id) ON DELETE RESTRICT,
    field varchar(32) NOT NULL CHECK(field IN ('name','status','os_family','ip_addresses','tags','environment')),
    mode varchar(16) NOT NULL CHECK(mode IN ('observed','manual')),
    source varchar(120),
    actor_id uuid REFERENCES users(id) ON DELETE SET NULL,
    observed_at timestamptz,
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY(asset_id,field)
);
CREATE TABLE inventory_reconciliation_conflicts (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    asset_id uuid NOT NULL REFERENCES inventory_assets(id) ON DELETE RESTRICT,
    workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
    source varchar(120) NOT NULL,
    field varchar(32) NOT NULL CHECK(field IN ('name','status','os_family','ip_addresses','tags','environment')),
    observed_value jsonb NOT NULL,
    observed_at timestamptz NOT NULL,
    first_seen_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    last_seen_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    seen_count bigint NOT NULL DEFAULT 1 CHECK(seen_count>0),
    resolved_at timestamptz
);
CREATE UNIQUE INDEX inventory_conflict_open_unique ON inventory_reconciliation_conflicts(asset_id,source,field) WHERE resolved_at IS NULL;
CREATE INDEX inventory_conflict_workspace_idx ON inventory_reconciliation_conflicts(workspace_id,asset_id,resolved_at);

-- Seed provenance for all existing objects without overwriting any field.
INSERT INTO inventory_field_provenance(asset_id,field,mode,source)
SELECT a.id,f.field,CASE WHEN a.source='manual' THEN 'manual' ELSE 'observed' END,a.source
FROM inventory_assets a CROSS JOIN (VALUES ('name'),('status'),('os_family'),('ip_addresses'),('tags'),('environment')) f(field);

INSERT INTO permissions(scope,description) VALUES
('inventory.history.read','View authorized asset revisions and field provenance'),
('inventory.reconcile.manage','Ingest deduplicated import observations and manage field overrides'),
('inventory.history.restore','Restore safe manual-asset fields from a prior revision')
ON CONFLICT(scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug='administrator' AND p.scope IN ('inventory.history.read','inventory.reconcile.manage','inventory.history.restore')
ON CONFLICT DO NOTHING;
