<script setup lang="ts">
import {apiError} from '~/composables/useApi'
import {syncCollectors,freshness,planState,type SyncObservation} from '~/composables/vmmanagerSync'
import type {VmmanagerView} from '~/composables/vmmanagerNavigation'
import type {ConnectorRun,CollectionJobPlan} from '~/composables/connectorLifecycle'
const props=defineProps<{targetId:string;healthStatus:string;profileConfigured:boolean;networkRead:boolean}>()
const emit=defineEmits<{(e:'open-view',view:VmmanagerView):void}>()
const api=useApi(),path=`/connectors/targets/${props.targetId}`
type Row={name:string;observation:SyncObservation|null;latest:ConnectorRun|null;success:ConnectorRun|null;error:string;historyError:string}
const health=ref<{health_status:string;health_checked_at:string|null}|null>(null),healthError=ref('')
const rows=ref<Row[]>([]),plans=ref<CollectionJobPlan[]>([]),plansError=ref(''),plansMore=ref(false),planOffset=ref(0),loading=ref(false),lastRefresh=ref(''),now=ref(Date.now())
const defs=computed(()=>syncCollectors.filter(c=>c.view!=='network'||props.networkRead))
const labels:Record<string,string>={fresh:'Свежий',overdue:'Давно не обновлялся',stale:'Устарел (профиль/credentials)',partial:'Неполный',not_collected:'Не собран',unknown:'Неизвестно',scheduled:'Активен',paused:'Приостановлен',expired:'Истёк',revoked:'Отозван'}
const visiblePlans=computed(()=>plans.value.filter(p=>p.collector_name!=='vmmanager.network'||props.networkRead))
let disposed=false,sequence=0,timer:ReturnType<typeof setInterval>|undefined
async function read<T>(suffix:string){return (await api.get<{data:T}>(path+suffix)).data}
function row(name:string){return rows.value.find(r=>r.name===name)}
function errorText(e:unknown){const p=apiError(e);return `${p.message}${p.code?' ('+p.code+')':''}`}
async function refresh(offset=planOffset.value){
 if(disposed||loading.value)return;const id=++sequence;loading.value=true;now.value=Date.now()
 const valid=()=>!disposed&&id===sequence
 try{
  const healthTask=read<{health_status:string;health_checked_at:string|null}>('').then(data=>{if(valid()){health.value=data;healthError.value=''}}).catch(e=>{if(valid()){health.value=null;healthError.value=errorText(e)}})
  const plansTask=read<CollectionJobPlan[]>(`/collection-plans?limit=50&offset=${offset}`).then(data=>{if(valid()){plans.value=data;plansMore.value=data.length===50;planOffset.value=offset;plansError.value=''}}).catch(e=>{if(valid()){plans.value=[];plansMore.value=false;plansError.value=errorText(e)}})
  // Bound concurrent requests: each collector uses at most three reads, no remote API calls or writes.
  for(const def of defs.value){
   if(!valid())return
   const q=`/runs?collector=${encodeURIComponent(def.name)}&limit=1`
   const [obs,latest,success]=await Promise.allSettled([def.endpoint?read<SyncObservation>('/'+def.endpoint):Promise.resolve(null),read<ConnectorRun[]>(q),read<ConnectorRun[]>(q+'&status=succeeded')])
   if(!valid())return
   now.value=Date.now()
   const r:Row={name:def.name,observation:obs.status==='fulfilled'?obs.value:null,latest:latest.status==='fulfilled'?latest.value[0]||null:null,success:success.status==='fulfilled'?success.value[0]||null:null,error:obs.status==='rejected'?errorText(obs.reason):'',historyError:[latest,success].filter(x=>x.status==='rejected').map(x=>errorText((x as PromiseRejectedResult).reason)).join('; ')}
   rows.value=[...rows.value.filter(x=>x.name!==r.name),r]
  }
  await Promise.all([plansTask,healthTask]);if(valid()){now.value=Date.now();lastRefresh.value=new Date().toISOString()}
 }finally{if(valid())loading.value=false}
}
watch(()=>props.networkRead,()=>{sequence++;loading.value=false;rows.value=[];plans.value=[];health.value=null;void refresh(0)})
onMounted(()=>{void refresh();timer=setInterval(()=>{now.value=Date.now();if(!document.hidden)void refresh()},30000)})
onBeforeUnmount(()=>{disposed=true;sequence++;if(timer)clearInterval(timer)})
function countsOf(result:unknown,key:string):Record<string,number>{
 if(!result||typeof result!=='object')return {}
 const value=(result as Record<string,unknown>)[key]
 if(!value||typeof value!=='object'||Array.isArray(value))return {}
 return Object.fromEntries(Object.entries(value).filter((entry):entry is [string,number]=>typeof entry[1]==='number'&&Number.isFinite(entry[1])))
}
function status(def:typeof syncCollectors[number]){return freshness(row(def.name)?.observation||null,now.value,def.maxAge)}
</script>
<template>
 <section class="panel" data-testid="vmmanager-sync-dashboard">
  <div class="toolbar"><div><h2>Sync Dashboard</h2><p class="muted">Сохранённые наблюдения и история workers. Не является live-состоянием гипервизора.</p></div><button class="btn" :disabled="loading" @click="refresh()">Обновить</button></div>
  <p>Подключение: {{health?.health_status||(!healthError?healthStatus:'неизвестно')}} · Последняя проверка: {{health?.health_checked_at||'—'}} · TLS-профиль: {{profileConfigured?'настроен':'не настроен'}}</p><p v-if="!profileConfigured" role="alert">Для расширенных сборщиков необходим профиль доверия.</p>
  <p v-if="healthError" role="alert">{{healthError}}</p><p v-if="loading" role="status">Обновление данных…</p><p v-if="lastRefresh" class="muted">Проверка панели: {{lastRefresh}}. Порог свежести: 15 минут, для задач — 3 минуты.</p>
  <div class="table-wrap"><table class="table"><thead><tr><th>Сборщик</th><th>Снимок / прогресс</th><th>Последний запуск</th><th>Последний успешный запуск</th><th>Раздел</th></tr></thead><tbody>
   <tr v-for="def in defs" :key="def.name" :data-testid="`vmm-sync-${def.name}`"><td><strong>{{def.label}}</strong><br><code>{{def.name}}</code></td>
    <td><template v-if="def.endpoint"><strong>{{labels[status(def)]||status(def)}}</strong><br>{{row(def.name)?.observation?.observed_at||'—'}}<p v-if="row(def.name)?.observation?.snapshot?.visibility" class="small">Видимость: {{row(def.name)?.observation?.snapshot?.visibility}}</p><p v-if="row(def.name)?.observation?.snapshot?.complete===false">Неполный снимок</p><p v-for="(n,k) in row(def.name)?.observation?.snapshot?.counts" :key="k" class="small">{{k}}: {{n}}</p><p v-if="row(def.name)?.observation?.summary" class="small">Курсор: {{row(def.name)?.observation?.summary?.high_water_id}} · повторный проход: {{row(def.name)?.observation?.summary?.sweep_after}} · {{row(def.name)?.observation?.summary?.caught_up?'Новые страницы просмотрены':'Загрузка продолжается'}}</p></template><span v-else>Нет отдельного снимка; см. историю запусков</span><p v-if="row(def.name)?.error" role="alert">{{row(def.name)?.error}}</p></td>
    <td>{{row(def.name)?.latest?.status||'—'}}<br>{{row(def.name)?.latest?.created_at||'—'}}<p v-if="row(def.name)?.latest">Попыток: {{row(def.name)?.latest?.attempts}}</p><p v-if="row(def.name)?.latest?.error_code" role="alert">{{row(def.name)?.latest?.error_code}}</p><p v-if="row(def.name)?.historyError" role="alert">{{row(def.name)?.historyError}}</p></td>
    <td>{{row(def.name)?.success?.finished_at||'—'}}<p v-if="row(def.name)?.success" class="small">{{row(def.name)?.success?.operation}} · succeeded</p><p v-for="(n,k) in countsOf(row(def.name)?.success?.result,'outcomes')" :key="k" class="small">{{k}}: {{n}}</p><p v-if="def.view==='network'&&row(def.name)?.observation?.snapshot?.ipam_sync" class="small">IPAM: создано {{row(def.name)?.observation?.snapshot?.ipam_sync?.created??'—'}} · обновлено {{row(def.name)?.observation?.snapshot?.ipam_sync?.updated??'—'}} · конфликты {{row(def.name)?.observation?.snapshot?.ipam_sync?.conflicts??'—'}}</p></td><td><button class="btn" @click="emit('open-view',def.view)">Открыть</button></td>
   </tr>
  </tbody></table></div>
  <h3>Расписания и разрешения</h3><p class="muted">Показана страница планов; состояние «активен» не гарантирует успешное выполнение. Управление и повторные запуски — в разделе «Планы сбора».</p>
  <p v-if="plansError" role="alert">{{plansError}}</p><div class="table-wrap"><table class="table"><thead><tr><th>План / сборщик</th><th>Операция</th><th>Состояние</th><th>Следующий запуск / срок</th></tr></thead><tbody><tr v-for="p in visiblePlans" :key="p.id"><td>{{p.name}}<br>{{p.collector_name}}</td><td>{{p.operation}}</td><td>{{labels[planState(p,now)]}}</td><td>{{p.next_due_at||'—'}}<br>{{p.expires_at}}</td></tr><tr v-if="!visiblePlans.length"><td colspan="4">Нет доступных планов на этой странице.</td></tr></tbody></table></div>
  <div class="inline-actions"><button class="btn" :disabled="loading||planOffset===0" @click="refresh(planOffset-50)">Назад</button><button class="btn" :disabled="loading||!plansMore||planOffset>=10000" @click="refresh(planOffset+50)">Далее</button><button class="btn" @click="emit('open-view','plans')">Управление планами</button></div>
 </section>
</template>
