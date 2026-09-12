-- CajeerServers PostgreSQL initial schema
CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE IF NOT EXISTS users (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    email varchar(255) NOT NULL UNIQUE,
    name varchar(255) NOT NULL,
    password_hash text NOT NULL,
    two_factor_enabled boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS roles (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    slug varchar(120) NOT NULL UNIQUE,
    name varchar(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS permissions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    scope varchar(180) NOT NULL UNIQUE,
    description text
);

CREATE TABLE IF NOT EXISTS api_tokens (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id uuid REFERENCES users(id) ON DELETE CASCADE,
    name varchar(255) NOT NULL,
    token_hash text NOT NULL,
    scopes jsonb NOT NULL DEFAULT '[]'::jsonb,
    last_used_at timestamptz,
    expires_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS credentials (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name varchar(255) NOT NULL,
    type varchar(80) NOT NULL,
    encrypted_payload bytea NOT NULL,
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS connectors (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    slug varchar(120) NOT NULL UNIQUE,
    type varchar(80) NOT NULL,
    name varchar(255) NOT NULL,
    enabled boolean NOT NULL DEFAULT true,
    config jsonb NOT NULL DEFAULT '{}'::jsonb,
    credential_id uuid REFERENCES credentials(id) ON DELETE SET NULL,
    last_sync_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS inventory_assets (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    type varchar(80) NOT NULL,
    source varchar(120) NOT NULL,
    external_id varchar(255),
    name varchar(255) NOT NULL,
    status varchar(80) NOT NULL DEFAULT 'unknown',
    os_family varchar(80),
    ip_addresses jsonb NOT NULL DEFAULT '[]'::jsonb,
    tags jsonb NOT NULL DEFAULT '[]'::jsonb,
    attributes jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (source, external_id)
);

CREATE TABLE IF NOT EXISTS sync_jobs (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    connector_id uuid REFERENCES connectors(id) ON DELETE CASCADE,
    mode varchar(40) NOT NULL,
    status varchar(40) NOT NULL DEFAULT 'queued',
    started_at timestamptz,
    finished_at timestamptz,
    summary jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS action_requests (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    actor_user_id uuid REFERENCES users(id) ON DELETE SET NULL,
    target_asset_id uuid REFERENCES inventory_assets(id) ON DELETE SET NULL,
    action varchar(120) NOT NULL,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    status varchar(40) NOT NULL DEFAULT 'pending',
    requires_approval boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    finished_at timestamptz
);

CREATE TABLE IF NOT EXISTS audit_log (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    actor_type varchar(40) NOT NULL,
    actor_id uuid,
    action varchar(180) NOT NULL,
    target_type varchar(120),
    target_id uuid,
    result varchar(40) NOT NULL,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS inventory_assets_search_idx ON inventory_assets USING gin (to_tsvector('simple', name || ' ' || coalesce(os_family, '') || ' ' || coalesce(external_id, '')));
