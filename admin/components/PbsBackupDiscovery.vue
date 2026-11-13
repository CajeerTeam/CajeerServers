<script setup lang="ts">
import {apiError} from '~/composables/useApi'
type Store={store:string;total:string|null;used:string|null;available:string|null;capacity_visible:boolean}
type Namespace={store:string;namespace:string}
type Group=Namespace&{backup_type:string;backup_id:string;last_backup:number|null;backup_count:string|null}
type Snapshot=Namespace&{backup_type:string;backup_id:string;backup_time:number;size:string|null;protected:boolean|null;verification:string|null}
type Job=Namespace&{kind:string;id:string;schedule:string|null;disabled:boolean|null;last_run:number|null;next_run:number|null;last_outcome:string|null;upid:string|null;remote:string|null;remote_store:string|null;direction:string|null;keep_hourly:string|null;keep_last:string|null;keep_daily:string|null;keep_weekly:string|null;keep_monthly:string|null;keep_yearly:string|null;gc_removed_bytes:string|null;gc_pending_bytes:string|null}
type Inventory={version:string;complete:boolean;scope:string;counts:Record<string,number>;datastores:Store[];namespaces:Namespace[];groups:Group[];snapshots:Snapshot[];jobs:Job[];issues:{section:string;reason:string;store:string|null;namespace:string|null}[]}
type State={state:string;observed_at:string|null;snapshot:Inventory|null}
type Run={id:string;status:string;created_at:string;error_code?:string;result?:{connection?:{pbs_discovery?:Inventory}}}
const props=defineProps<{targetId:string}>(),api=useApi(),path=`/connectors/targets/${props.targetId}`
const state=ref<State|null>(null),runs=ref<Run[]>([]),selected=ref<Run|null>(null),loading=ref(false),error=ref(''),store=ref(''),namespace=ref<string|null>(null),search=ref(''),tab=ref<'groups'|'snapshots'|'jobs'>('snapshots'),page=ref(0),offset=ref(0)
let disposed=false,seq=0,timer:ReturnType<typeof setInterval>|undefined
const inventory=computed(()=>selected.value?selected.value.result?.connection?.pbs_discovery??null:state.value?.snapshot??null)
const match=(r:Namespace,text:string)=> (!store.value||r.store===store.value)&&(namespace.value===null||r.namespace===namespace.value)&&text.toLowerCase().includes(search.value.trim().toLowerCase())
const groups=computed(()=>(inventory.value?.groups??[]).filter(r=>match(r,`${r.store} ${r.namespace} ${r.backup_type}/${r.backup_id}`)))
const snapshots=computed(()=>(inventory.value?.snapshots??[]).filter(r=>match(r,`${r.store} ${r.namespace} ${r.backup_type}/${r.backup_id}`)).sort((a,b)=>b.backup_time-a.backup_time))
const jobs=computed(()=>(inventory.value?.jobs??[]).filter(r=>match(r,`${r.store} ${r.namespace} ${r.kind} ${r.id} ${r.last_outcome??''}`)))
const total=computed(()=>tab.value==='groups'?groups.value.length:tab.value==='snapshots'?snapshots.value.length:jobs.value.length)
const namespaces=computed(()=>[...new Set((inventory.value?.namespaces??[]).filter(r=>!store.value||r.store===store.value).map(r=>r.namespace))])
watch([search,namespace,tab,inventory],()=>page.value=0);watch(store,()=>{namespace.value=null;page.value=0})
const bytes=(v:string|null)=>{if(v===null)return '—';const n=BigInt(v),units=['B','KiB','MiB','GiB','TiB','PiB','EiB'];let d=1n,i=0;while(i<units.length-1&&n>=d*1024n){d*=1024n;i++}return `${n/d}${i?'.'+String(n%d*10n/d):''} ${units[i]}`}
const date=(v:number|null)=>v===null?'—':new Date(v*1000).toISOString().replace('T',' ').replace('.000Z',' UTC')
async function read<T>(suffix:string){return (await api.get<{data:T}>(path+suffix)).data}
async function refresh(next=offset.value){if(loading.value||disposed)return;const n=++seq;loading.value=true;error.value='';try{const [s,r]=await Promise.all([read<State>('/pbs-backups'),read<Run[]>(`/runs?collector=pbs.backups&limit=20&offset=${next}`)]);if(!disposed&&n===seq){state.value=s;runs.value=r;offset.value=next}}catch(e){if(!disposed&&n===seq){error.value=apiError(e).message;state.value=null;runs.value=[];selected.value=null}}finally{if(!disposed&&n===seq)loading.value=false}}
async function history(id:string){if(loading.value||disposed)return;const n=++seq;loading.value=true;error.value='';try{const r=await read<Run>('/runs/'+id);if(!disposed&&n===seq){selected.value=r;store.value='';namespace.value=null}}catch(e){if(!disposed&&n===seq){error.value=apiError(e).message;selected.value=null}}finally{if(!disposed&&n===seq)loading.value=false}}
onMounted(()=>{void refresh();timer=setInterval(()=>{if(!document.hidden&&!selected.value)void refresh()},15000)});onBeforeUnmount(()=>{disposed=true;seq++;if(timer)clearInterval(timer)})
</script>
<template>
<section class="panel" data-testid="pbs-backup-discovery">
  <h2>Proxmox Backup Server — хранилища, backups и jobs</h2>
  <p class="muted">Запустите план <code>pbs.backups</code> (Inspect). Это сохранённое наблюдение в пределах ACL API-токена, а не гарантия видимости всех backups.</p>
  <button class="btn" :disabled="loading" @click="selected=null;refresh()">Текущий снимок / обновить</button>
  <p v-if="error" role="alert">{{error}}</p>
  <p v-if="selected">История: {{selected.created_at}} — {{selected.status}} {{selected.error_code}}</p>
  <p v-else-if="state">{{state.state}} · {{state.observed_at||'—'}}</p>
  <p v-if="state?.state==='stale'&&!selected" role="alert">Конфигурация или credential изменились: выполните новый сбор.</p>
  <template v-if="inventory">
    <p>PBS {{inventory.version}} · {{inventory.complete?'Сбор завершён':'Частичный сбор'}} · {{inventory.counts.datastores}} datastores / {{inventory.counts.groups}} групп / {{inventory.counts.snapshots}} snapshots / {{inventory.counts.jobs}} jobs</p>
    <details v-if="inventory.issues.length"><summary>Ограничения сбора ({{inventory.issues.length}})</summary><ul><li v-for="(issue,i) in inventory.issues" :key="i">{{issue.section}} · {{issue.store||'—'}} / {{issue.namespace||'root'}} · {{issue.reason}}</li></ul></details>
    <div class="table-wrap"><table class="table"><thead><tr><th>Datastore</th><th>Всего</th><th>Занято</th><th>Доступно</th></tr></thead><tbody><tr v-for="r in inventory.datastores" :key="r.store"><td>{{r.store}}</td><td>{{bytes(r.total)}}</td><td>{{bytes(r.used)}}</td><td>{{bytes(r.available)}}</td></tr></tbody></table></div>
    <div class="form-row"><label>Datastore<select v-model="store"><option value="">Все</option><option v-for="r in inventory.datastores" :key="r.store" :value="r.store">{{r.store}}</option></select></label><label>Namespace<select v-model="namespace"><option :value="null">Все</option><option value="">root</option><option v-for="ns in namespaces.filter(Boolean)" :key="ns" :value="ns">{{ns}}</option></select></label><label>Поиск<input v-model="search" maxlength="128" /></label><label>Раздел<select v-model="tab"><option value="snapshots">Snapshots</option><option value="groups">Groups</option><option value="jobs">Jobs</option></select></label></div>
    <div class="table-wrap"><table v-if="tab==='snapshots'" class="table"><thead><tr><th>Datastore / namespace</th><th>Backup</th><th>Время</th><th>Логический размер</th><th>Защита / verify</th></tr></thead><tbody><tr v-for="r in snapshots.slice(page*50,(page+1)*50)" :key="JSON.stringify([r.store,r.namespace,r.backup_type,r.backup_id,r.backup_time])"><td>{{r.store}} / {{r.namespace||'root'}}</td><td>{{r.backup_type}}/{{r.backup_id}}</td><td>{{date(r.backup_time)}}</td><td>{{bytes(r.size)}}</td><td>{{r.protected===null?'—':r.protected?'protected':'unprotected'}} / {{r.verification||'unknown'}}</td></tr></tbody></table>
    <table v-else-if="tab==='groups'" class="table"><thead><tr><th>Datastore / namespace</th><th>Группа</th><th>Последний backup</th><th>Snapshots</th></tr></thead><tbody><tr v-for="r in groups.slice(page*50,(page+1)*50)" :key="JSON.stringify([r.store,r.namespace,r.backup_type,r.backup_id])"><td>{{r.store}} / {{r.namespace||'root'}}</td><td>{{r.backup_type}}/{{r.backup_id}}</td><td>{{date(r.last_backup)}}</td><td>{{r.backup_count??'—'}}</td></tr></tbody></table>
    <table v-else class="table"><thead><tr><th>Job</th><th>Datastore / namespace</th><th>Расписание</th><th>Последний запуск</th><th>Следующий</th><th>Детали</th></tr></thead><tbody><tr v-for="r in jobs.slice(page*50,(page+1)*50)" :key="r.kind+':'+r.id"><td>{{r.kind}} / {{r.id}}</td><td>{{r.store}} / {{r.namespace||'root'}}</td><td>{{r.disabled?'disabled':r.schedule||'—'}}</td><td>{{date(r.last_run)}} · {{r.last_outcome||'unknown'}}</td><td>{{date(r.next_run)}}</td><td><details><summary>Параметры</summary><div v-if="r.kind==='sync'">{{r.direction||'pull'}} · {{r.remote||'local'}} / {{r.remote_store||'—'}}</div><div v-if="r.kind==='prune'">keep: last {{r.keep_last??'—'}}, hourly {{r.keep_hourly??'—'}}, daily {{r.keep_daily??'—'}}, weekly {{r.keep_weekly??'—'}}, monthly {{r.keep_monthly??'—'}}, yearly {{r.keep_yearly??'—'}}</div><div v-if="r.kind==='gc'">removed {{bytes(r.gc_removed_bytes)}} / pending {{bytes(r.gc_pending_bytes)}}</div><small>{{r.upid||'—'}}</small></details></td></tr></tbody></table></div>
    <p v-if="!total">Нет доступных записей.</p><button class="btn" :disabled="page===0" @click="page--">Назад</button> {{page*50+Math.min(1,total)}}–{{Math.min((page+1)*50,total)}} / {{total}} <button class="btn" :disabled="(page+1)*50>=total" @click="page++">Далее</button>
  </template>
  <h3>История сборов</h3><div class="table-wrap"><table class="table"><thead><tr><th>Время</th><th>Состояние</th><th>Результат</th></tr></thead><tbody><tr v-for="r in runs" :key="r.id"><td>{{r.created_at}}</td><td>{{r.status}} {{r.error_code}}</td><td><button class="btn" :disabled="loading||r.status!=='succeeded'" @click="history(r.id)">Открыть</button></td></tr></tbody></table></div><button class="btn" :disabled="loading||offset===0" @click="refresh(Math.max(0,offset-20))">Назад</button> <button class="btn" :disabled="loading||runs.length<20" @click="refresh(offset+20)">Далее</button>
</section>
</template>
