-- Permit internal host hardware observations, preserving all existing manual metadata.
ALTER TABLE inventory_field_provenance DROP CONSTRAINT inventory_field_provenance_field_check;
ALTER TABLE inventory_field_provenance ADD CONSTRAINT inventory_field_provenance_field_check CHECK(field IN ('name','status','os_family','ip_addresses','tags','environment','attributes'));
ALTER TABLE inventory_reconciliation_conflicts DROP CONSTRAINT inventory_reconciliation_conflicts_field_check;
ALTER TABLE inventory_reconciliation_conflicts ADD CONSTRAINT inventory_reconciliation_conflicts_field_check CHECK(field IN ('name','status','os_family','ip_addresses','tags','environment','attributes'));
INSERT INTO inventory_field_provenance(asset_id,field,mode,source)
SELECT id,'attributes','manual','manual' FROM inventory_assets WHERE attributes ? 'host_hardware'
ON CONFLICT(asset_id,field) DO NOTHING;
CREATE FUNCTION cajeer_host_attributes_provenance() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 changed boolean;
BEGIN
 IF NEW.source <> 'manual' OR current_setting('cajeer.change_origin',true)='observation' THEN RETURN NULL; END IF;
 IF TG_OP='INSERT' THEN
  changed := NEW.attributes ? 'host_hardware';
 ELSE
  changed := (OLD.attributes->'host_hardware') IS DISTINCT FROM (NEW.attributes->'host_hardware');
 END IF;
 IF changed THEN
  INSERT INTO inventory_field_provenance(asset_id,field,mode,source,actor_id)
  VALUES(NEW.id,'attributes','manual','manual',NULLIF(current_setting('cajeer.actor_id',true),'')::uuid)
  ON CONFLICT(asset_id,field) DO UPDATE SET mode='manual',source='manual',actor_id=EXCLUDED.actor_id,observed_at=NULL,updated_at=clock_timestamp();
 END IF;
 RETURN NULL;
END;
$$;
CREATE TRIGGER inventory_host_attributes_provenance AFTER INSERT OR UPDATE ON inventory_assets FOR EACH ROW EXECUTE FUNCTION cajeer_host_attributes_provenance();
