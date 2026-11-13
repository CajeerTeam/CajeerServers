-- Unknown outcomes are revisited independently of active tasks and submitted actions.
CREATE INDEX proxmox_tasks_unknown_pending ON proxmox_tasks(target_id,scope_hash,checked_at ASC NULLS FIRST,id) WHERE state='unknown';
