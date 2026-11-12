import { queryUuid } from './inventoryNavigation.ts'
export type CollectionView = 'collectors'|'plans'|'metrics'|'ingestion'|'exports'
export interface CollectionAccess { read:boolean; inventory:boolean; ingestion:boolean; transfer:boolean }
export function collectionViews(access:CollectionAccess):CollectionView[] {
  if(!access.read)return []
  return ['collectors',...(access.inventory?['plans','metrics',...(access.ingestion?['ingestion']:[]),...(access.transfer?['exports']:[])]:[])] as CollectionView[]
}
export function collectionView(requested:unknown,allowed:CollectionView[]):CollectionView {
  return typeof requested==='string'&&allowed.includes(requested as CollectionView)?requested as CollectionView:allowed[0]||'collectors'
}
export function collectionUrl(workspace:unknown,target:unknown,view:CollectionView):string {
  const p=new URLSearchParams();const w=queryUuid(workspace),t=queryUuid(target)
  if(w)p.set('workspace_id',w);if(w&&t)p.set('target_id',t);p.set('view',view)
  return '/collections?'+p.toString()
}
export function scopedTarget<T extends {workspace_id:string}>(target:T|null,workspace:string):T|null {
  return target&&target.workspace_id===workspace?target:null
}
