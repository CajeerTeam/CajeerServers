import {queryUuid} from './inventoryNavigation.ts'
export type ProxmoxView = 'overview'|'guests'|'storage'|'tasks'|'actions'|'backups'|'plans'
export type ProxmoxProvider = 'proxmox'|'pbs'
export const proxmoxLabels: Record<ProxmoxView,string> = {
  overview:'Кластер и узлы',guests:'VM и LXC',storage:'Хранилища и сеть',tasks:'Задачи UPID',actions:'Управление гостями',backups:'Резервные копии PBS',plans:'Планы сбора',
}
export function guestIdentity(value:unknown):string {
  return typeof value==='string'&&/^(qemu|lxc):[1-9][0-9]{0,8}$/.test(value)?value:''
}
export function proxmoxViews(provider:string,inventory:boolean,actions:boolean):ProxmoxView[] {
  if(provider==='pbs')return inventory?['backups','plans']:[]
  if(provider!=='proxmox')return []
  return [...(inventory?['overview','guests','storage','tasks','plans'] as const:[]),...(actions&&inventory?['actions'] as const:[])]
}
export function proxmoxView(value:unknown,allowed:ProxmoxView[]):ProxmoxView|undefined {
  return typeof value==='string'&&allowed.includes(value as ProxmoxView)?value as ProxmoxView:allowed[0]
}
export function scopedProxmox<T extends {workspace_id:string;provider:string}>(target:T|null,workspace:string):T|null {
  return target&&target.workspace_id===workspace&&['proxmox','pbs'].includes(target.provider)?target:null
}
export function proxmoxUrl(workspace:unknown,target?:unknown,view?:ProxmoxView,guest?:unknown):string {
  const p=new URLSearchParams(),w=queryUuid(workspace),t=queryUuid(target),g=guestIdentity(guest)
  if(w)p.set('workspace_id',w)
  if(w&&t){p.set('target_id',t);if(view)p.set('view',view);if(view==='actions'&&g)p.set('guest',g)}
  return '/proxmox'+(p.size?'?'+p:'')
}
