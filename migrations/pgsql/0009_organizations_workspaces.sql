-- First-class tenancy: existing assets are migrated without changing their IDs or contents.
CREATE TABLE organizations (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    slug varchar(64) NOT NULL UNIQUE CHECK (slug ~ '^[a-z][a-z0-9-]{2,63}$'),
    name varchar(255) NOT NULL CHECK (name ~ '[^[:space:]]'),
    created_by uuid REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workspaces (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
    slug varchar(64) NOT NULL CHECK (slug ~ '^[a-z][a-z0-9-]{2,63}$'),
    name varchar(255) NOT NULL CHECK (name ~ '[^[:space:]]'),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, slug),
    UNIQUE (id, organization_id)
);
CREATE INDEX workspaces_org_idx ON workspaces (organization_id, id);
CREATE TABLE workspace_members (
    workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role varchar(16) NOT NULL CHECK (role IN ('owner','manager','viewer')),
    added_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (workspace_id,user_id)
);
CREATE INDEX workspace_members_user_idx ON workspace_members (user_id,workspace_id);

INSERT INTO organizations (slug,name) VALUES ('default','Default organization');
INSERT INTO workspaces (organization_id,slug,name)
SELECT id,'default','Default workspace' FROM organizations WHERE slug='default';
-- Only active administrators receive access on upgrade. No arbitrary user gains access.
INSERT INTO workspace_members(workspace_id,user_id,role)
SELECT w.id,u.id,'owner' FROM workspaces w
JOIN organizations o ON o.id=w.organization_id AND o.slug='default'
JOIN users u ON u.is_active=true
JOIN user_roles ur ON ur.user_id=u.id
JOIN roles r ON r.id=ur.role_id AND r.slug='administrator'
WHERE w.slug='default' ON CONFLICT DO NOTHING;

ALTER TABLE inventory_assets ADD COLUMN workspace_id uuid REFERENCES workspaces(id) ON DELETE RESTRICT;
ALTER TABLE inventory_assets ADD COLUMN owner_user_id uuid REFERENCES users(id) ON DELETE SET NULL;
UPDATE inventory_assets SET workspace_id=(SELECT w.id FROM workspaces w JOIN organizations o ON o.id=w.organization_id WHERE o.slug='default' AND w.slug='default') WHERE workspace_id IS NULL;
ALTER TABLE inventory_assets ALTER COLUMN workspace_id SET NOT NULL;
CREATE INDEX inventory_assets_workspace_id_idx ON inventory_assets(workspace_id,id);
CREATE INDEX inventory_assets_owner_id_idx ON inventory_assets(owner_user_id) WHERE owner_user_id IS NOT NULL;
-- Source identity is workspace-local. Preserve existing data with an equivalent per-workspace unique index.
ALTER TABLE inventory_assets DROP CONSTRAINT IF EXISTS inventory_assets_source_external_id_key;
CREATE UNIQUE INDEX inventory_assets_workspace_source_external_uidx ON inventory_assets(workspace_id,source,external_id) WHERE external_id IS NOT NULL;
-- Ownership is not a way to bypass membership. The workspace service verifies authorization in the same transaction.
CREATE OR REPLACE FUNCTION cajeer_inventory_owner_membership() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.owner_user_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM workspace_members wm JOIN users u ON u.id=wm.user_id
        WHERE wm.workspace_id=NEW.workspace_id AND wm.user_id=NEW.owner_user_id AND u.is_active=true
    ) THEN RAISE EXCEPTION 'Asset owner must be an active workspace member' USING ERRCODE='23514'; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER inventory_owner_membership_check BEFORE INSERT OR UPDATE OF owner_user_id,workspace_id ON inventory_assets
FOR EACH ROW EXECUTE FUNCTION cajeer_inventory_owner_membership();

INSERT INTO permissions(scope,description) VALUES
('organizations.manage','Create and update organizations'),
('workspaces.read','Read own workspace metadata and assets'),
('workspaces.manage','Create and update workspaces'),
('workspaces.members.manage','Manage workspace memberships'),
('inventory.ownership.manage','Assign and move assets between accessible workspaces')
ON CONFLICT (scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator' AND p.scope IN (
    'organizations.manage','workspaces.read','workspaces.manage','workspaces.members.manage','inventory.ownership.manage'
) ON CONFLICT DO NOTHING;
