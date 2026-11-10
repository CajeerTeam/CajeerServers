-- 0.2.8: durable import batches and workspace-scoped credential references; no secrets copied.
CREATE TABLE inventory_import_batches (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
    actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    format varchar(8) NOT NULL CHECK(format IN ('csv','json')),
    idempotency_key varchar(120) NOT NULL,
    payload_sha256 char(64) NOT NULL,
    rows_payload jsonb NOT NULL CHECK(jsonb_typeof(rows_payload)='array'),
    row_count integer NOT NULL CHECK(row_count BETWEEN 1 AND 200),
    processed_rows integer NOT NULL DEFAULT 0 CHECK(processed_rows>=0 AND processed_rows<=row_count),
    queue_job_id uuid,
    status varchar(32) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','enqueued','running','completed','completed_with_errors')),
    summary jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    started_at timestamptz,
    finished_at timestamptz,
    UNIQUE(workspace_id,actor_id,idempotency_key)
);
CREATE INDEX inventory_import_batches_workspace_idx ON inventory_import_batches(workspace_id,created_at DESC);
CREATE TABLE inventory_import_results (
    batch_id uuid NOT NULL REFERENCES inventory_import_batches(id) ON DELETE CASCADE,
    row_number integer NOT NULL CHECK(row_number BETWEEN 1 AND 200),
    outcome varchar(40) NOT NULL CHECK(outcome IN ('created','applied','conflicted','unchanged','stale','error')),
    error_code varchar(80),
    asset_id uuid REFERENCES inventory_assets(id) ON DELETE RESTRICT,
    PRIMARY KEY(batch_id,row_number)
);
CREATE TABLE inventory_credential_scopes (
    credential_id uuid PRIMARY KEY REFERENCES credentials(id) ON DELETE CASCADE,
    workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
    scoped_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE(credential_id,workspace_id)
);
CREATE TABLE inventory_asset_credential_bindings (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
    asset_id uuid NOT NULL,
    credential_id uuid NOT NULL,
    purpose varchar(64) NOT NULL CHECK(purpose ~ '^[a-z][a-z0-9_.-]{0,63}$'),
    created_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    FOREIGN KEY(asset_id,workspace_id) REFERENCES inventory_assets(id,workspace_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    FOREIGN KEY(credential_id,workspace_id) REFERENCES inventory_credential_scopes(credential_id,workspace_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    UNIQUE(asset_id,credential_id,purpose)
);
CREATE INDEX inventory_asset_credential_bindings_asset_idx ON inventory_asset_credential_bindings(workspace_id,asset_id);
INSERT INTO permissions(scope,description) VALUES
('inventory.transfer.read','Preview imports and export authorized inventory'),
('inventory.transfer.manage','Queue and inspect inventory imports'),
('inventory.credentials.read','View scoped credential binding references'),
('inventory.credentials.manage','Attach or detach credential references'),
('inventory.credentials.scope','Enroll existing credentials into an authorized workspace')
ON CONFLICT(scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug='administrator' AND p.scope IN ('inventory.transfer.read','inventory.transfer.manage','inventory.credentials.read','inventory.credentials.manage','inventory.credentials.scope')
ON CONFLICT DO NOTHING;
