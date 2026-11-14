import type {VmmanagerView} from './vmmanagerNavigation.ts'
export const syncCollectors: {name:string;view:VmmanagerView;label:string;endpoint?:string;maxAge:number}[]=[
 {name:'vmmanager.cluster',view:'cluster',label:'Кластеры и узлы',endpoint:'vmmanager-cluster',maxAge:900},
 {name:'vmmanager.guests',view:'guests',label:'VM и LXD',endpoint:'vmmanager-guests',maxAge:900},
 {name:'vmmanager.network',view:'network',label:'Сети и IPAM',endpoint:'vmmanager-network',maxAge:900},
 {name:'vmmanager.storage',view:'storage',label:'Хранилища',endpoint:'vmmanager-storage',maxAge:900},
 {name:'vmmanager.templates',view:'templates',label:'Шаблоны',endpoint:'vmmanager-templates',maxAge:900},
 {name:'vmmanager.tasks',view:'tasks',label:'Задачи',endpoint:'vmmanager-tasks?limit=1',maxAge:180},
 {name:'vmmanager.inventory',view:'plans',label:'Базовый Inventory',maxAge:900},
 {name:'vmmanager.metrics',view:'metrics',label:'Метрики',maxAge:900},
]
export type SyncObservation={state:string;observed_at:string|null;snapshot?:{complete?:boolean;visibility?:string;counts?:Record<string,number>;ipam_sync?:Record<string,unknown>};summary?:{high_water_id:number;sweep_after:number;caught_up:boolean;visibility:string;observed_count:number;new_tasks:number;completed_tasks:number}|null}
export function freshness(observation:SyncObservation|null,now:number,maxAge:number):string {
 if(!observation)return 'unknown'
 if(observation.state!=='available')return observation.state
 const age=(now-Date.parse(observation.observed_at||''))/1000
 if(!Number.isFinite(age)||age<0)return 'unknown'
 if(age>maxAge)return 'overdue'
 if(observation.snapshot?.complete===false||observation.summary?.visibility==='restricted')return 'partial'
 return 'fresh'
}
export function planState(p:{enabled:boolean;revoked_at:string|null;expires_at:string;next_due_at:string|null},now:number):string {
 if(p.revoked_at)return 'revoked'
 if(!Number.isFinite(Date.parse(p.expires_at))||Date.parse(p.expires_at)<=now)return 'expired'
 if(!p.enabled)return 'paused'
 return 'scheduled'
}
