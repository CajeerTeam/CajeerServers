import { queryUuid } from './inventoryNavigation.ts'
import type { LifecycleTarget } from './connectorLifecycle.ts'

export const osProviders = ['ssh','winrm','snmp','routeros'] as const
export type OsProvider = typeof osProviders[number]
export const osProviderLabels: Record<OsProvider,string> = {ssh:'Linux / SSH',winrm:'Windows / WinRM',snmp:'SNMP',routeros:'MikroTik RouterOS'}
export const healthLabels: Record<string,string> = {healthy:'Доступен',degraded:'Сбой',unreachable:'Недоступен',auth_failed:'Ошибка авторизации',disabled:'Отключён',unknown:'Не проверен'}
export type OsView = 'overview'|'discovery'|'events'|'metrics'|'backups'|'plans'|'topology'
export const osViewLabels:Record<OsView,string>={overview:'Состояние',discovery:'Inventory',events:'События',metrics:'Метрики',backups:'Конфигурации',plans:'Планы и запуски',topology:'Топология / IPAM'}
export type OsTarget=LifecycleTarget & {provider:OsProvider;endpoint_host:string;endpoint_port:number;health_status:string;health_checked_at:string|null;health_success_at:string|null;health_error_code:string|null;circuit_open_until:string|null;os_network_profile?:unknown}
export function osProvider(v:unknown):OsProvider|null{return typeof v==='string'&&(osProviders as readonly string[]).includes(v)?v as OsProvider:null}
export function scopedOsTarget(t:OsTarget|null,ws:string):OsTarget|null{return t&&ws&&t.workspace_id===ws&&osProvider(t.provider)?t:null}
export function osViews(provider:OsProvider|null,can:(p:string)=>boolean):OsView[]{
 if(!can('workspaces.read')||!can('connectors.targets.read'))return []
 const v:OsView[]=['overview'];if(!provider)return v
 if(can('inventory.assets.read'))v.push('discovery','metrics','plans')
 if(can('system.events.read'))v.push('events')
 if(provider==='routeros'&&can('network.configuration.read'))v.push('backups')
 if(can('inventory.assets.read')&&can('inventory.network.read')&&can('inventory.relationships.read'))v.push('topology')
 return v
}
export function osView(v:unknown,allowed:OsView[]):OsView{return typeof v==='string'&&allowed.includes(v as OsView)?v as OsView:'overview'}
export function osNetworkUrl(ws:unknown,target?:unknown,view:OsView='overview',provider?:unknown):string{
 const q=new URLSearchParams(),w=queryUuid(ws),t=queryUuid(target),p=osProvider(provider)
 if(w)q.set('workspace_id',w);if(w&&t)q.set('target_id',t);if(p)q.set('provider',p);if(view!=='overview')q.set('view',view)
 return '/os-network'+(q.size?'?'+q:'')
}
export type OsSummary = {total:number;healthy:number;problem:number;disabled:number;unchecked:number;without_profile:number;unlinked:number}
export function isOsSummary(value:unknown):value is OsSummary{
 if(!value||typeof value!=='object')return false
 const r=value as OsSummary
 const keys:(keyof OsSummary)[]=['total','healthy','problem','disabled','unchecked','without_profile','unlinked']
 return keys.every(k=>Number.isSafeInteger(r[k])&&r[k]>=0)&&r.healthy+r.problem+r.disabled+r.unchecked===r.total&&r.without_profile<=r.total&&r.unlinked<=r.total
}
export function sumOsSummaries(rows:OsSummary[]):OsSummary{
 const result:OsSummary={total:0,healthy:0,problem:0,disabled:0,unchecked:0,without_profile:0,unlinked:0}
 for(const row of rows)for(const key of Object.keys(result) as (keyof OsSummary)[])result[key]+=row[key]
 return result
}
export const osScopes:Record<OsProvider,string[]>={ssh:['system','software','services','storage','network'],winrm:['system','software','services','storage','network','updates'],snmp:['device','interfaces','topology'],routeros:['inventory','network','routing']}
export const osDiscoveryPath:Record<OsProvider,string>={ssh:'linux-discovery',winrm:'windows-discovery',snmp:'snmp-discovery',routeros:'routeros-discovery'}
