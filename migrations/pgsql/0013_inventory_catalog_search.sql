-- 0.2.6: workspace-scoped taxonomy, groups, environments and PostgreSQL search.
-- Historical assets, identifiers and legacy JSONB tags remain intact.
CREATE TABLE inventory_tags (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
    slug varchar(64) NOT NULL CHECK (slug ~ '^[a-z0-9][a-z0-9._-]{0,63}$'),
    name varchar(128) NOT NULL CHECK (name ~ '[^[:space:]]'),
    description varchar(1024) NOT NULL DEFAULT '',
    version bigint NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(workspace_id,slug),
    UNIQUE(id,workspace_id)
);
CREATE TABLE inventory_groups (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
    slug varchar(64) NOT NULL CHECK (slug ~ '^[a-z0-9][a-z0-9._-]{0,63}$'),
    name varchar(128) NOT NULL CHECK (name ~ '[^[:space:]]'),
    description varchar(1024) NOT NULL DEFAULT '',
    version bigint NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(workspace_id,slug),
    UNIQUE(id,workspace_id)
);
CREATE TABLE inventory_asset_tags (
    workspace_id uuid NOT NULL,
    asset_id uuid NOT NULL,
    tag_id uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY(asset_id,tag_id),
    FOREIGN KEY(asset_id,workspace_id) REFERENCES inventory_assets(id,workspace_id) ON DELETE CASCADE,
    FOREIGN KEY(tag_id,workspace_id) REFERENCES inventory_tags(id,workspace_id) ON DELETE RESTRICT
);
CREATE TABLE inventory_group_assets (
    workspace_id uuid NOT NULL,
    asset_id uuid NOT NULL,
    group_id uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY(group_id,asset_id),
    FOREIGN KEY(asset_id,workspace_id) REFERENCES inventory_assets(id,workspace_id) ON DELETE CASCADE,
    FOREIGN KEY(group_id,workspace_id) REFERENCES inventory_groups(id,workspace_id) ON DELETE RESTRICT
);
CREATE INDEX inventory_asset_tags_lookup_idx ON inventory_asset_tags(workspace_id,tag_id,asset_id);
CREATE INDEX inventory_group_assets_lookup_idx ON inventory_group_assets(workspace_id,asset_id,group_id);
ALTER TABLE inventory_assets ADD COLUMN environment varchar(64) NOT NULL DEFAULT 'unassigned'
    CONSTRAINT inventory_assets_environment_valid CHECK (environment ~ '^[a-z][a-z0-9_-]{0,63}$');
CREATE INDEX inventory_assets_env_lookup_idx ON inventory_assets(workspace_id,environment,id) WHERE deleted_at IS NULL;
CREATE INDEX inventory_assets_owner_lookup_idx ON inventory_assets(workspace_id,owner_user_id,id) WHERE deleted_at IS NULL;
CREATE INDEX inventory_assets_name_search_idx ON inventory_assets USING gin(to_tsvector('simple'::regconfig,coalesce(name,'') || ' ' || coalesce(external_id,'')));
CREATE INDEX inventory_assets_external_search_idx ON inventory_assets(workspace_id,source,external_id) WHERE external_id IS NOT NULL;

-- Migrate valid legacy tag slugs without rewriting assets or changing their UUIDs.
INSERT INTO inventory_tags(workspace_id,slug,name)
SELECT DISTINCT a.workspace_id, v.tag, v.tag
FROM inventory_assets a
CROSS JOIN LATERAL jsonb_array_elements_text(CASE WHEN jsonb_typeof(a.tags)='array' THEN a.tags ELSE '[]'::jsonb END) AS v(tag)
WHERE v.tag ~ '^[a-z0-9][a-z0-9._-]{0,63}$'
ON CONFLICT(workspace_id,slug) DO NOTHING;
INSERT INTO inventory_asset_tags(workspace_id,asset_id,tag_id)
SELECT DISTINCT a.workspace_id,a.id,t.id
FROM inventory_assets a
CROSS JOIN LATERAL jsonb_array_elements_text(CASE WHEN jsonb_typeof(a.tags)='array' THEN a.tags ELSE '[]'::jsonb END) AS v(tag)
JOIN inventory_tags t ON t.workspace_id=a.workspace_id AND t.slug=v.tag
ON CONFLICT DO NOTHING;

-- Keep legacy Inventory CRUD and direct SQL tags updates aligned with the normalized index.
-- No dynamic SQL and no untrusted user-provided identifiers are executed.
CREATE FUNCTION cajeer_inventory_sync_tags() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    INSERT INTO inventory_tags(workspace_id,slug,name)
    SELECT DISTINCT NEW.workspace_id,v.tag,v.tag
    FROM jsonb_array_elements_text(CASE WHEN jsonb_typeof(NEW.tags)='array' THEN NEW.tags ELSE '[]'::jsonb END) v(tag)
    WHERE v.tag ~ '^[a-z0-9][a-z0-9._-]{0,63}$'
    ON CONFLICT(workspace_id,slug) DO NOTHING;
    DELETE FROM inventory_asset_tags i WHERE i.asset_id=NEW.id
      AND NOT EXISTS (SELECT 1 FROM inventory_tags t
                      WHERE t.id=i.tag_id AND t.workspace_id=NEW.workspace_id
                        AND NEW.tags ? t.slug);
    INSERT INTO inventory_asset_tags(workspace_id,asset_id,tag_id)
    SELECT DISTINCT NEW.workspace_id,NEW.id,t.id
    FROM inventory_tags t
    WHERE t.workspace_id=NEW.workspace_id AND NEW.tags ? t.slug
    ON CONFLICT DO NOTHING;
    RETURN NULL;
END;
$$;
CREATE FUNCTION cajeer_inventory_workspace_taxonomy_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.workspace_id IS DISTINCT FROM NEW.workspace_id THEN
        DELETE FROM inventory_group_assets WHERE asset_id=OLD.id;
        DELETE FROM inventory_asset_tags WHERE asset_id=OLD.id;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER inventory_workspace_taxonomy_guard BEFORE UPDATE OF workspace_id ON inventory_assets
FOR EACH ROW EXECUTE FUNCTION cajeer_inventory_workspace_taxonomy_guard();
CREATE TRIGGER inventory_asset_tags_sync AFTER INSERT OR UPDATE OF tags,workspace_id ON inventory_assets
FOR EACH ROW EXECUTE FUNCTION cajeer_inventory_sync_tags();
CREATE FUNCTION cajeer_inventory_catalog_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    NEW.version:=OLD.version+1;
    NEW.updated_at:=clock_timestamp();
    RETURN NEW;
END;
$$;
CREATE TRIGGER inventory_group_bump BEFORE UPDATE ON inventory_groups
FOR EACH ROW EXECUTE FUNCTION cajeer_inventory_catalog_version();
CREATE TRIGGER inventory_tag_bump BEFORE UPDATE ON inventory_tags
FOR EACH ROW EXECUTE FUNCTION cajeer_inventory_catalog_version();

-- Direct association writes must also update optimistic group versions.
CREATE FUNCTION cajeer_inventory_group_member_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    UPDATE inventory_groups SET updated_at=clock_timestamp()
    WHERE id=(CASE WHEN TG_OP='DELETE' THEN OLD.group_id ELSE NEW.group_id END);
    RETURN NULL;
END;
$$;
CREATE TRIGGER inventory_group_member_bump AFTER INSERT OR DELETE ON inventory_group_assets
FOR EACH ROW EXECUTE FUNCTION cajeer_inventory_group_member_version();
CREATE FUNCTION cajeer_inventory_group_active_asset() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM inventory_assets
       WHERE id=NEW.asset_id AND workspace_id=NEW.workspace_id AND deleted_at IS NULL) THEN
       RAISE EXCEPTION 'Cannot attach a deleted or foreign asset' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER inventory_group_active_asset BEFORE INSERT OR UPDATE ON inventory_group_assets
FOR EACH ROW EXECUTE FUNCTION cajeer_inventory_group_active_asset();

INSERT INTO permissions(scope,description) VALUES
 ('inventory.catalog.read','Read workspace tags, groups, and group memberships'),
 ('inventory.catalog.manage','Manage workspace tags, groups, and group memberships')
ON CONFLICT(scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator'
AND p.scope IN ('inventory.catalog.read','inventory.catalog.manage')
ON CONFLICT DO NOTHING;
