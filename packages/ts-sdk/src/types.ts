export type AssetType = 'server' | 'hypervisor_node' | 'cluster' | 'virtual_machine' | 'container' | 'router' | 'workstation' | 'other'
export interface InfrastructureAsset { id: string; type: AssetType; name: string; status: string; source: string }
export type Provider = 'proxmox' | 'vmmanager' | 'ssh' | 'winrm' | 'snmp' | 'custom' | 'prometheus'
export type Operation = 'health' | 'inspect' | 'sync'
export type PowerAction = 'vm.start' | 'vm.shutdown'
export interface CollectorDefinition { name:string; provider:Provider; operations:('inspect'|'sync')[]; resource_types:AssetType[] }
export interface CollectionSnapshot { schema_version:1; collector:string; sink:'result'|'inventory'|'metrics'; metric_samples?:MetricSample[]; observed_at:string; complete:boolean; count:number; observations?:{type:AssetType;external_id:string;fields:Record<string,unknown>;parent_external_id?:string}[] }
export interface Driver { collectors?:CollectorDefinition[]; provider: Provider; purpose: string; credentialTypes: string[]; operations: (Operation | 'validate_rotation')[]; resourceTypes: AssetType[]; transport: string; actions: PowerAction[]; write_actions_enabled: boolean }
export interface TargetInput { workspace_id: string; provider: Provider; name: string; endpoint_host: string; endpoint_port: number; asset_id?: string }
export interface Target extends TargetInput { id: string; version: number; etag: string; state: 'active' | 'disabled'; health_status: string; health_checked_at: string | null }
export interface CollectionJobPlan { id:string; grant_id:string; name:string; collector_name:string; actor_id:string; target_id:string; workspace_id:string; operation:Exclude<Operation,'health'>; enabled:boolean; interval_seconds:number|null; jitter_seconds:number; cron_expression:string|null; timezone:string; next_due_at:string; expires_at:string; revoked_at:string|null }
export type CollectionPlanInput = { name:string; collector:string; operation:Exclude<Operation,'health'>; ttl_seconds:number; jitter_seconds?:number } & ({ interval_seconds:number; cron?:never; timezone?:never } | { cron:string; timezone?:string; interval_seconds?:never })
export interface CollectionAttempt { attempt:number; started_at:string; finished_at:string|null; outcome:string; error_code:string|null }
export interface ServiceGrant { collection_plan_id?:string|null; id: string; target_id: string; operation: Operation; expires_at: string; revoked_at: string | null }
export interface EnqueuedRun { id:string; deduplicated:boolean }
export interface Run { collection_plan_id?:string|null; redrive_of?:string|null; attempt_history?:CollectionAttempt[]; collector_name?:string|null; id: string; target_id: string; status: 'queued' | 'running' | 'succeeded' | 'failed' | 'cancelled'; operation: Operation; result?: ({collection?:CollectionSnapshot;[key:string]:unknown})|null; error_code?: string | null }
export interface Action { id: string; target_id: string; external_id: string; action: PowerAction; status: 'dispatching' | 'submitted' | 'succeeded' | 'failed' | 'unknown' | 'acknowledged'; remote_task: string | null; error_code: string | null; created_at: string; updated_at: string }
export interface Problem { title?: string; code?: string; request_id?: string; status?: number }

export interface MetricSample {metric:string;value:string;resource_external_id:string;labels:Record<string,string>;kind:'gauge'|'counter';unit:string|null;observed_at:string|null}
export interface MetricSeries {id:string;asset_id:string;target_version:number;metric:string;resource_external_id:string;labels:Record<string,string>;kind:'gauge'|'counter';unit:string|null;last_seen_at:string}
export type MetricAggregation='raw'|'avg'|'min'|'max'|'count'|'rate'
export interface MetricQuery {series:{id:string;kind:string;metric:string;unit:string|null};aggregation:MetricAggregation;points:{timestamp:string;value:string}[]}
