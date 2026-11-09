-- 0.2.4 Network Inventory / IPAM. No changes to legacy inventory_assets JSONB fields.
-- Serializes network allocation by workspace to reject overlapping CIDRs even under concurrent writers.
CREATE TABLE inventory_networks (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
    name varchar(120) NOT NULL CHECK (length(btrim(name)) > 0),
    cidr cidr NOT NULL,
    vlan_id integer CHECK (vlan_id BETWEEN 1 AND 4094),
    description varchar(1024) NOT NULL DEFAULT '',
    version bigint NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (id,workspace_id),
    UNIQUE (workspace_id,cidr)
);
CREATE INDEX inventory_networks_workspace_idx ON inventory_networks(workspace_id,name,id);
-- Prefix lookups use PostgreSQL network operators; no extension-dependent index is required at this scale.

ALTER TABLE inventory_assets ADD CONSTRAINT inventory_assets_id_workspace_unique UNIQUE(id,workspace_id);

CREATE TABLE inventory_interfaces (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
    asset_id uuid NOT NULL,
    FOREIGN KEY (asset_id,workspace_id) REFERENCES inventory_assets(id,workspace_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    name varchar(120) NOT NULL CHECK (length(btrim(name)) > 0),
    mac_address macaddr,
    version bigint NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (id,workspace_id),
    UNIQUE (asset_id,name)
);
CREATE INDEX inventory_interfaces_workspace_idx ON inventory_interfaces(workspace_id,asset_id,id);
CREATE UNIQUE INDEX inventory_interfaces_mac_unique_idx ON inventory_interfaces(workspace_id,mac_address) WHERE mac_address IS NOT NULL;

CREATE TABLE inventory_ip_addresses (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
    network_id uuid NOT NULL,
    address inet NOT NULL,
    interface_id uuid,
    hostname varchar(253),
    description varchar(1024) NOT NULL DEFAULT '',
    version bigint NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (masklen(address) = CASE family(address) WHEN 4 THEN 32 ELSE 128 END),
    FOREIGN KEY (network_id,workspace_id) REFERENCES inventory_networks(id,workspace_id) ON DELETE RESTRICT,
    FOREIGN KEY (interface_id,workspace_id) REFERENCES inventory_interfaces(id,workspace_id) ON DELETE RESTRICT,
    UNIQUE (workspace_id,address)
);
CREATE INDEX inventory_ip_addresses_network_idx ON inventory_ip_addresses(network_id,address);
CREATE INDEX inventory_ip_addresses_interface_idx ON inventory_ip_addresses(interface_id) WHERE interface_id IS NOT NULL;
CREATE INDEX inventory_ip_addresses_workspace_idx ON inventory_ip_addresses(workspace_id,id);

CREATE FUNCTION cajeer_ipam_network_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    -- Serializes all network creations in the same workspace, including direct SQL writers.
    PERFORM 1 FROM workspaces WHERE id=NEW.workspace_id FOR UPDATE;
    IF EXISTS (SELECT 1 FROM inventory_networks n WHERE n.workspace_id=NEW.workspace_id AND n.id<>NEW.id AND n.cidr && NEW.cidr) THEN
        RAISE EXCEPTION 'Overlapping network within workspace' USING ERRCODE='23505';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.cidr IS DISTINCT FROM OLD.cidr AND EXISTS (
        SELECT 1 FROM inventory_ip_addresses a WHERE a.network_id=NEW.id AND NOT (a.address <<= NEW.cidr)
    ) THEN
        RAISE EXCEPTION 'Cannot move CIDR while it contains allocated addresses' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER inventory_network_guard BEFORE INSERT OR UPDATE OF cidr,workspace_id ON inventory_networks
FOR EACH ROW EXECUTE FUNCTION cajeer_ipam_network_guard();

CREATE FUNCTION cajeer_ipam_interface_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM 1 FROM workspaces WHERE id=NEW.workspace_id FOR UPDATE;
    IF NOT EXISTS (SELECT 1 FROM inventory_assets a WHERE a.id=NEW.asset_id AND a.workspace_id=NEW.workspace_id AND a.deleted_at IS NULL) THEN
        RAISE EXCEPTION 'Interface requires an active asset in the same workspace' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER inventory_interface_guard BEFORE INSERT OR UPDATE OF asset_id,workspace_id ON inventory_interfaces
FOR EACH ROW EXECUTE FUNCTION cajeer_ipam_interface_guard();

CREATE FUNCTION cajeer_ipam_address_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE network_cidr cidr;
BEGIN
    PERFORM 1 FROM workspaces WHERE id=NEW.workspace_id FOR UPDATE;
    SELECT n.cidr INTO network_cidr FROM inventory_networks n WHERE n.id=NEW.network_id AND n.workspace_id=NEW.workspace_id;
    IF network_cidr IS NULL OR NOT (NEW.address <<= network_cidr) THEN
        RAISE EXCEPTION 'IP address is outside its network' USING ERRCODE='23514';
    END IF;
    IF NEW.interface_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM inventory_interfaces i JOIN inventory_assets a ON a.id=i.asset_id
        WHERE i.id=NEW.interface_id AND i.workspace_id=NEW.workspace_id AND a.deleted_at IS NULL
    ) THEN
        RAISE EXCEPTION 'Assigned interface must belong to an active asset in the workspace' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER inventory_ip_address_guard BEFORE INSERT OR UPDATE OF network_id,address,interface_id,workspace_id ON inventory_ip_addresses
FOR EACH ROW EXECUTE FUNCTION cajeer_ipam_address_guard();

CREATE FUNCTION cajeer_ipam_bump_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    NEW.version := OLD.version + 1;
    NEW.updated_at := clock_timestamp();
    RETURN NEW;
END;
$$;
CREATE TRIGGER inventory_network_version BEFORE UPDATE ON inventory_networks FOR EACH ROW EXECUTE FUNCTION cajeer_ipam_bump_version();
CREATE TRIGGER inventory_interface_version BEFORE UPDATE ON inventory_interfaces FOR EACH ROW EXECUTE FUNCTION cajeer_ipam_bump_version();
CREATE TRIGGER inventory_ip_address_version BEFORE UPDATE ON inventory_ip_addresses FOR EACH ROW EXECUTE FUNCTION cajeer_ipam_bump_version();

INSERT INTO permissions(scope,description) VALUES
('inventory.network.read','Read authorized networks, interfaces and addresses'),
('inventory.network.manage','Manage network segments, interfaces, reservations and IP assignments')
ON CONFLICT (scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug='administrator' AND p.scope IN ('inventory.network.read','inventory.network.manage')
ON CONFLICT DO NOTHING;
