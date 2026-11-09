-- A database-backed, singleton installation record. The first admin and this
-- record must be committed together in one PostgreSQL transaction.
CREATE TABLE cajeer_installation (
    singleton_id smallint PRIMARY KEY DEFAULT 1 CHECK (singleton_id = 1),
    installation_id uuid NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    admin_user_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    installed_at timestamptz NOT NULL DEFAULT now()
);
