/** Pure UI policy helpers. The API remains authoritative for all authorization. */
export interface LifecycleTarget { provider?:string; proxmox_profile?:unknown; id:string; workspace_id:string; name:string; version:number; etag:string; state:string; asset_id:string|null }
export interface LifecycleBinding { credential_id:string; purpose:string; credential_version:number|string }
export interface ServiceGrant {
  id:string; collection_plan_id?:string|null; actor_id:string; operation:'health'|'inspect'|'sync'; target_version:number|string; credential_id:string; credential_version:number|string;
  expires_at:string; revoked_at:string|null; interval_seconds:number|string|null; next_due_at:string|null
}
export interface CollectorDefinition { name:string; provider:string; operations:string[]; resource_types:string[] }
export interface CollectionJobPlan { id:string; grant_id:string; name:string; collector_name:string; actor_id:string; operation:string; enabled:boolean; interval_seconds:number|null; jitter_seconds:number; cron_expression:string|null; timezone:string; next_due_at:string; expires_at:string; revoked_at:string|null }
export interface ConnectorRun { collection_plan_id?:string|null; redrive_of?:string|null; attempt_history?:{attempt:number;started_at:string;finished_at:string|null;outcome:string;error_code:string|null}[]; collector_name?:string|null; id:string; grant_id:string; operation:string; status:string; attempts:number|string; created_at:string; finished_at:string|null; error_code:string|null; result:unknown }
export interface WebhookSource { id:string; grant_id:string; actor_id:string; key_version:number|string; revoked_at:string|null; created_at:string }
export interface ConnectorEvent { id:string; event_id:string; event_type:string; resource_id:string|null; source_id:string; status:string; run_id:string; received_at:string; error_code:string|null; dispatch_count:number; dispatches:{run_id:string;dispatched_at:string}[] }
export interface ConnectorAction { id:string; action:string; external_id:string; status:string; remote_task:string|null; node:string|null; error_code:string|null; created_at:string; updated_at:string }
export interface LifecycleResource { external_id:string; resource_type:string; asset_id:string|null; presence:string; conflict_code:string|null }
export function usableGrant(g:ServiceGrant,t:LifecycleTarget,actor:string, binding:LifecycleBinding|null,now:number):boolean {
  return !g.collection_plan_id && t.state==='active' && !!t.asset_id && !!binding && g.actor_id===actor && !g.revoked_at && Date.parse(g.expires_at)>now
    && Number(g.target_version)===t.version && g.credential_id===binding.credential_id && Number(g.credential_version)===Number(binding.credential_version)
}
export function grantLimits(ttl:number,interval:number|null):boolean {
  return Number.isInteger(ttl)&&ttl>=60&&ttl<=2592000&&(interval===null||(Number.isInteger(interval)&&interval>=60&&interval<=86400))
}
export function canPowerResource(r:LifecycleResource):boolean {
  return r.presence==='seen'&&!!r.asset_id&&!r.conflict_code&&((r.resource_type==='virtual_machine'&&/^qemu:[1-9][0-9]{0,8}$/.test(r.external_id))||(r.resource_type==='container'&&/^lxc:[1-9][0-9]{0,8}$/.test(r.external_id)))
}
export function isRunPending(status:string):boolean {return ['queued','running'].includes(status)}
export function canRedrive(status:string):boolean {return ['dead_letter','cancelled'].includes(status)}
export function canAcknowledge(status:string):boolean {return ['unknown','dispatching','submitted'].includes(status)}
export function actionConfirmation(action:string,external:string):string {return `${action} ${external}`}

export function powerActionMatches(action:string,external:string):boolean { return /^(vm|lxc)\.(start|shutdown|stop|reboot)$/.test(action)&&/^(qemu|lxc):[1-9][0-9]{0,8}$/.test(external)&&(action.startsWith('vm.')===external.startsWith('qemu:')) }
