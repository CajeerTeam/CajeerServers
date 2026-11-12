export type AssetType = 'server' | 'hypervisor_node' | 'cluster' | 'virtual_machine' | 'container' | 'router' | 'workstation' | 'other'
export interface InfrastructureAsset { id: string; type: AssetType; name: string; status: string; source: string }
export type Provider = 'proxmox' | 'vmmanager' | 'ssh' | 'winrm' | 'snmp' | 'custom'
export type Operation = 'health' | 'inspect' | 'sync'
export type PowerAction = 'vm.start' | 'vm.shutdown'
export interface Driver { provider: Provider; purpose: string; credentialTypes: string[]; operations: (Operation | 'validate_rotation')[]; resourceTypes: AssetType[]; transport: string; actions: PowerAction[] }
export interface TargetInput { workspace_id: string; provider: Provider; name: string; endpoint_host: string; endpoint_port: number; asset_id?: string }
export interface Target extends TargetInput { id: string; version: number; etag: string; state: 'active' | 'disabled'; health_status: string; health_checked_at: string | null }
export interface ServiceGrant { id: string; target_id: string; operation: Operation; expires_at: string; revoked_at: string | null }
export interface Run { id: string; target_id: string; status: 'queued' | 'running' | 'succeeded' | 'failed' | 'cancelled'; operation: Operation; result?: unknown; error_code?: string | null }
export interface Action { id: string; target_id: string; external_id: string; action: PowerAction; status: 'dispatching' | 'submitted' | 'succeeded' | 'failed' | 'unknown' | 'acknowledged'; remote_task: string | null; error_code: string | null; created_at: string; updated_at: string }
export interface Problem { title?: string; code?: string; request_id?: string; status?: number }
