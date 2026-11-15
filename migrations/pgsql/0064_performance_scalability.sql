-- Offline migration: bounded, workspace-scoped read paths. Preserve existing indexes/constraints.
CREATE INDEX inventory_assets_active_name_page ON inventory_assets(workspace_id,name,id) WHERE deleted_at IS NULL;
CREATE INDEX inventory_assets_active_updated_page ON inventory_assets(workspace_id,updated_at DESC,id DESC) WHERE deleted_at IS NULL;
CREATE INDEX inventory_assets_active_type_page ON inventory_assets(workspace_id,type,created_at DESC,id DESC) WHERE deleted_at IS NULL;
CREATE INDEX inventory_assets_active_status_page ON inventory_assets(workspace_id,status,created_at DESC,id DESC) WHERE deleted_at IS NULL;
CREATE INDEX inventory_group_assets_group_page ON inventory_group_assets(workspace_id,group_id,asset_id);
CREATE INDEX system_events_target_severity_page ON system_events(target_id,severity,id DESC);
CREATE INDEX system_events_target_source_page ON system_events(target_id,source,id DESC);
CREATE INDEX metric_series_workspace_seen ON metric_series(workspace_id,last_seen_at,id);
CREATE INDEX connector_targets_provider_page ON connector_targets(workspace_id,provider,created_at DESC,id DESC);
