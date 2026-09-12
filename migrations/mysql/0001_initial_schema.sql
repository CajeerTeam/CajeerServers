-- CajeerServers MySQL/MariaDB compatibility initial schema
CREATE TABLE IF NOT EXISTS users (
    id char(36) PRIMARY KEY,
    email varchar(255) NOT NULL UNIQUE,
    name varchar(255) NOT NULL,
    password_hash text NOT NULL,
    two_factor_enabled boolean NOT NULL DEFAULT false,
    created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS credentials (
    id char(36) PRIMARY KEY,
    name varchar(255) NOT NULL,
    type varchar(80) NOT NULL,
    encrypted_payload blob NOT NULL,
    metadata json NOT NULL,
    created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS connectors (
    id char(36) PRIMARY KEY,
    slug varchar(120) NOT NULL UNIQUE,
    type varchar(80) NOT NULL,
    name varchar(255) NOT NULL,
    enabled boolean NOT NULL DEFAULT true,
    config json NOT NULL,
    credential_id char(36) NULL,
    last_sync_at timestamp NULL,
    created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory_assets (
    id char(36) PRIMARY KEY,
    type varchar(80) NOT NULL,
    source varchar(120) NOT NULL,
    external_id varchar(255),
    name varchar(255) NOT NULL,
    status varchar(80) NOT NULL DEFAULT 'unknown',
    os_family varchar(80),
    ip_addresses json NOT NULL,
    tags json NOT NULL,
    attributes json NOT NULL,
    created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_source_external (source, external_id)
);
