-- 0.2.5: typed workspace-scoped relationships between Inventory assets.
-- Composite foreign keys, workspace locks, and recursive cycle checks are enforced by PostgreSQL.
CREATE TABLE inventory_relationships (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
    source_asset_id uuid NOT NULL,
    target_asset_id uuid NOT NULL,
    kind varchar(32) NOT NULL CHECK (kind IN ('cluster_contains','hosts','depends_on','connected_to')),
    version bigint NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (source_asset_id,workspace_id) REFERENCES inventory_assets(id,workspace_id) ON DELETE RESTRICT,
    FOREIGN KEY (target_asset_id,workspace_id) REFERENCES inventory_assets(id,workspace_id) ON DELETE RESTRICT,
    CHECK (source_asset_id <> target_asset_id),
    UNIQUE (workspace_id,source_asset_id,target_asset_id,kind),
    CHECK (kind <> 'connected_to' OR source_asset_id < target_asset_id)
);
CREATE INDEX inventory_relations_source_idx ON inventory_relationships(workspace_id,source_asset_id,kind);
CREATE INDEX inventory_relations_target_idx ON inventory_relationships(workspace_id,target_asset_id,kind);

CREATE FUNCTION cajeer_relationship_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE source_type text;
DECLARE target_type text;
DECLARE path_exists boolean;
BEGIN
    -- Locking the workspace serializes all graph edits, including independent edges
    -- which would otherwise concurrently create a cycle.
    PERFORM 1 FROM workspaces WHERE id=NEW.workspace_id FOR UPDATE;
    SELECT type INTO source_type FROM inventory_assets
     WHERE id=NEW.source_asset_id AND workspace_id=NEW.workspace_id AND deleted_at IS NULL;
    SELECT type INTO target_type FROM inventory_assets
     WHERE id=NEW.target_asset_id AND workspace_id=NEW.workspace_id AND deleted_at IS NULL;
    IF source_type IS NULL OR target_type IS NULL THEN
        RAISE EXCEPTION 'Relationship endpoint is missing, deleted or outside workspace' USING ERRCODE='23514';
    END IF;
    IF NEW.kind = 'cluster_contains' AND (source_type <> 'cluster' OR target_type <> 'hypervisor_node') THEN
        RAISE EXCEPTION 'Only cluster to hypervisor node membership is supported' USING ERRCODE='23514';
    END IF;
    IF NEW.kind = 'hosts' AND (source_type NOT IN ('hypervisor_node','server') OR target_type NOT IN ('virtual_machine','container')) THEN
        RAISE EXCEPTION 'Invalid host / guest pair' USING ERRCODE='23514';
    END IF;
    IF NEW.kind = 'connected_to' AND NEW.source_asset_id > NEW.target_asset_id THEN
        RAISE EXCEPTION 'Undirected connected_to must have canonical ordering' USING ERRCODE='23514';
    END IF;
    IF NEW.kind IN ('cluster_contains','hosts') AND EXISTS (
        SELECT 1 FROM inventory_relationships r WHERE r.workspace_id=NEW.workspace_id
          AND r.target_asset_id=NEW.target_asset_id AND r.kind IN ('cluster_contains','hosts') AND r.id<>NEW.id
    ) THEN
        RAISE EXCEPTION 'This infrastructure asset already has a structural parent' USING ERRCODE='23505';
    END IF;
    IF NEW.kind IN ('cluster_contains','hosts','depends_on') THEN
        WITH RECURSIVE reachable(id) AS (
            SELECT NEW.target_asset_id
            UNION
            SELECT r.target_asset_id FROM inventory_relationships r
            JOIN reachable x ON r.source_asset_id=x.id
            WHERE r.workspace_id=NEW.workspace_id
              AND r.id<>NEW.id
              AND (CASE WHEN NEW.kind='depends_on' THEN r.kind='depends_on'
                        ELSE r.kind IN ('cluster_contains','hosts') END)
        ) SELECT EXISTS (SELECT 1 FROM reachable WHERE id=NEW.source_asset_id) INTO path_exists;
        IF path_exists THEN RAISE EXCEPTION 'Relationship introduces a cycle' USING ERRCODE='23514'; END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER inventory_relationship_guard BEFORE INSERT OR UPDATE OF workspace_id,source_asset_id,target_asset_id,kind
ON inventory_relationships FOR EACH ROW EXECUTE FUNCTION cajeer_relationship_guard();

-- Do not allow direct SQL to soft-delete, retype, or move assets still referenced by active relations.
CREATE FUNCTION cajeer_asset_relationship_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (NEW.deleted_at IS DISTINCT FROM OLD.deleted_at AND NEW.deleted_at IS NOT NULL)
       OR NEW.workspace_id IS DISTINCT FROM OLD.workspace_id
       OR NEW.type IS DISTINCT FROM OLD.type THEN
        -- Coordinate direct SQL updates with relationship INSERT/UPDATE, not just application services.
        PERFORM 1 FROM workspaces WHERE id=OLD.workspace_id FOR UPDATE;
        IF EXISTS (SELECT 1 FROM inventory_relationships r
          WHERE r.source_asset_id=OLD.id OR r.target_asset_id=OLD.id) THEN
            RAISE EXCEPTION 'Detach infrastructure relationships before changing or deleting this asset'
                USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER inventory_asset_relationship_guard BEFORE UPDATE OF workspace_id,deleted_at,type ON inventory_assets
FOR EACH ROW EXECUTE FUNCTION cajeer_asset_relationship_guard();

CREATE TRIGGER inventory_relationship_version BEFORE UPDATE ON inventory_relationships FOR EACH ROW EXECUTE FUNCTION cajeer_ipam_bump_version();
INSERT INTO permissions(scope,description) VALUES
 ('inventory.relationships.read','Read workspace infrastructure relationships and topology'),
 ('inventory.relationships.manage','Create, edit and delete workspace infrastructure relations')
ON CONFLICT (scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator'
AND p.scope IN ('inventory.relationships.read','inventory.relationships.manage') ON CONFLICT DO NOTHING;
