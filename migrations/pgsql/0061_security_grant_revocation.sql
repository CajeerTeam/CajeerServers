-- Existing delegations predate permanent revocation on loss of authorization.
-- No reliable historical reconstruction is possible: require fresh step-up for all.
UPDATE connector_service_grants SET revoked_at=clock_timestamp() WHERE revoked_at IS NULL;
UPDATE collection_plans SET enabled=false WHERE enabled;
DELETE FROM vault_use_grants;
CREATE INDEX connector_grants_actor_live ON connector_service_grants(actor_id,workspace_id) WHERE revoked_at IS NULL;
CREATE INDEX vault_use_grants_actor_workspace ON vault_use_grants(actor_id,workspace_id);
