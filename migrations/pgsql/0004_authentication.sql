-- 0.1.5: server-persisted, individually revocable sessions; atomic login throttling.
-- Existing users and tokens retain their original identifiers and data.
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active boolean NOT NULL DEFAULT true;
-- Prevent case-variant duplicates, ensuring unambiguous login lookup.
CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email));

CREATE TABLE auth_sessions (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    secret_hash char(64) NOT NULL,
    csrf_hash char(64) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    last_seen_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL,
    revoked_at timestamptz,
    CONSTRAINT auth_sessions_expiry CHECK (expires_at > created_at)
);
CREATE INDEX auth_sessions_user_active_idx ON auth_sessions (user_id, expires_at) WHERE revoked_at IS NULL;
CREATE INDEX auth_sessions_expiry_idx ON auth_sessions (expires_at);

CREATE TABLE auth_login_attempts (
    subject_hash char(64) PRIMARY KEY,
    failed_count integer NOT NULL DEFAULT 0 CHECK (failed_count >= 0),
    window_started timestamptz NOT NULL DEFAULT now(),
    locked_until timestamptz
);
CREATE INDEX auth_login_attempts_gc_idx ON auth_login_attempts (window_started);

ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS revoked_at timestamptz;
CREATE INDEX api_tokens_active_owner_idx ON api_tokens (user_id, expires_at) WHERE revoked_at IS NULL;
