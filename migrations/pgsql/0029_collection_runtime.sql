-- Named collection jobs reuse the existing service grant, lease and atomic result commit.
ALTER TABLE connector_runs ADD COLUMN collector_name varchar(64)
    CHECK (collector_name IS NULL OR collector_name ~ '^[a-z][a-z0-9_.-]{0,63}$');
