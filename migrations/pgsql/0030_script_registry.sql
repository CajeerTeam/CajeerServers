CREATE TABLE scripts (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL REFERENCES workspaces(id),
 name varchar(255) NOT NULL, description text NOT NULL DEFAULT '', created_by uuid NOT NULL REFERENCES users(id),
 revision integer NOT NULL DEFAULT 1, archived_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(workspace_id,id)
);
CREATE TABLE script_signing_keys (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL REFERENCES workspaces(id),
 name varchar(255) NOT NULL, public_key char(64) NOT NULL CHECK(public_key ~ '^[0-9a-f]{64}$'),
 created_by uuid NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now(), revoked_at timestamptz,
 UNIQUE(workspace_id,public_key), UNIQUE(workspace_id,id)
);
CREATE TABLE script_versions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL, script_id uuid NOT NULL,
 version integer NOT NULL CHECK(version>0), source text NOT NULL CHECK(octet_length(source) BETWEEN 1 AND 65536),
 signing_bytes text NOT NULL, created_by uuid NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now(),
 state text NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','pending_review','approved','revoked')),
 key_id uuid, signature char(128), approved_by uuid REFERENCES users(id), approved_at timestamptz, revoked_at timestamptz,
 FOREIGN KEY(workspace_id,script_id) REFERENCES scripts(workspace_id,id), FOREIGN KEY(workspace_id,key_id) REFERENCES script_signing_keys(workspace_id,id),
 UNIQUE(script_id,version), CHECK((key_id IS NULL)=(signature IS NULL)),
 CHECK(state NOT IN ('pending_review','approved') OR signature IS NOT NULL), CHECK(signature IS NULL OR signature ~ '^[0-9a-f]{128}$'), CHECK(state<>'approved' OR (approved_at IS NOT NULL AND approved_by IS NOT NULL)),
 CHECK((state='revoked')=(revoked_at IS NOT NULL))
);
CREATE INDEX scripts_workspace_idx ON scripts(workspace_id,created_at,id);
CREATE INDEX script_versions_key_idx ON script_versions(key_id);
CREATE FUNCTION script_version_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF (NEW.id,NEW.workspace_id,NEW.script_id,NEW.version,NEW.source,NEW.signing_bytes,NEW.created_by,NEW.created_at) IS DISTINCT FROM
 (OLD.id,OLD.workspace_id,OLD.script_id,OLD.version,OLD.source,OLD.signing_bytes,OLD.created_by,OLD.created_at) THEN RAISE EXCEPTION 'immutable script version'; END IF;
 IF OLD.state='revoked' OR NOT ((OLD.state='draft' AND NEW.state IN ('pending_review','revoked')) OR (OLD.state='pending_review' AND NEW.state IN ('approved','revoked')) OR (OLD.state='approved' AND NEW.state='revoked')) THEN RAISE EXCEPTION 'invalid script transition'; END IF;
 IF OLD.signature IS NOT NULL AND (NEW.signature,NEW.key_id) IS DISTINCT FROM (OLD.signature,OLD.key_id) THEN RAISE EXCEPTION 'immutable script signature'; END IF;
 RETURN NEW; END $$;
CREATE TRIGGER script_version_guard BEFORE UPDATE ON script_versions FOR EACH ROW EXECUTE FUNCTION script_version_immutable();
INSERT INTO permissions(scope,description) VALUES ('scripts.read','Read scripts and templates'),('scripts.manage','Manage script drafts and metadata'),('scripts.approve','Manage trust and approve or revoke versions'),('scripts.execute','Validate approved execution eligibility') ON CONFLICT(scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator' AND p.scope IN ('scripts.read','scripts.manage','scripts.approve','scripts.execute') ON CONFLICT DO NOTHING;
