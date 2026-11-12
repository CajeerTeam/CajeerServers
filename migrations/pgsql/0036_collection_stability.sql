-- Maintenance and worker fairness indexes; existing records and migration checksums remain unchanged.
CREATE INDEX script_execution_retention_idx ON script_executions(finished_at,id) WHERE state IN ('succeeded','failed','cancelled');
CREATE INDEX data_export_workspace_ready_idx ON data_export_jobs(workspace_id,available_at,id) WHERE status='queued';
