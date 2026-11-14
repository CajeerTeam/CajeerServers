<script setup lang="ts">
import {apiError} from '~/composables/useApi'
import {osScopes,osDiscoveryPath,healthLabels,osViews,type OsTarget,type OsView} from '~/composables/osNetworkNavigation'
const props=defineProps<{target:OsTarget}>(),emit=defineEmits<{open:[view:OsView]}>(),auth=useAuth(),api=useApi()
type Snapshot={state:string;observed_at:string|null;snapshot:{complete:boolean;counts:Record<string,number>}|null}
type Run={id:string;collector_name:string|null;operation:string;status:string;created_at:string;error_code:string|null}
type Version={id:string;version:number;observed_at:string;byte_size:number}
const scopes=ref<{name:string;data:Snapshot|null;error:string}[]>([]),runs=ref<Run[]>([]),backup=ref<Version|null>(null),busy=ref(false),runError=ref(''),backupError=ref('')
const states:Record<string,string>={available:'Доступен',stale:'Устарел',not_collected:'Не собран'}
const views=computed(()=>osViews(props.target.provider,auth.can))
let seq=0,disposed=false
async function read<T>(suffix:string){return (await api.get<{data:T}>(`/connectors/targets/${props.target.id}${suffix}`)).data}
async function load(){const n=++seq;busy.value=true;scopes.value=[];runs.value=[];backup.value=null;runError.value='';backupError.value='';const valid=()=>!disposed&&n===seq
 const tasks:Promise<void>[]=[]
 if(views.value.includes('discovery')){tasks.push(Promise.all(osScopes[props.target.provider].map(async name=>{try{return {name,data:await read<Snapshot>(`/${osDiscoveryPath[props.target.provider]}?scope=${name}`),error:''}}catch(e){return {name,data:null,error:apiError(e).message}}})).then(r=>{if(valid())scopes.value=r}));tasks.push(read<Run[]>('/runs?limit=5&offset=0').then(r=>{if(valid())runs.value=r}).catch(e=>{if(valid())runError.value=apiError(e).message}))}
 if(views.value.includes('backups'))tasks.push(read<Version[]>('/configuration-backups?limit=1&offset=0').then(r=>{if(valid())backup.value=r[0]||null}).catch(e=>{if(valid())backupError.value=apiError(e).message}))
 await Promise.all(tasks);if(valid())busy.value=false
}
onMounted(()=>void load());onBeforeUnmount(()=>{disposed=true;seq++})
</script>
<template><section class="panel" data-testid="os-target-overview"><div class="toolbar"><h2>Состояние устройства</h2><button class="btn" :disabled="busy" @click="load">Обновить снимки</button></div><dl class="kv-grid"><div><dt>Доступность</dt><dd>{{healthLabels[target.health_status]||target.health_status}}</dd></div><div><dt>Последняя проверка</dt><dd>{{target.health_checked_at||'—'}}</dd></div><div><dt>Последний успех</dt><dd>{{target.health_success_at||'—'}}</dd></div><div><dt>Ошибка / circuit breaker</dt><dd>{{target.health_error_code||'—'}} / {{target.circuit_open_until||'—'}}</dd></div><div><dt>Профиль доверия</dt><dd>{{target.os_network_profile?'Настроен':'Не настроен'}}</dd></div><div><dt>Ревизия подключения</dt><dd>{{target.version}}</dd></div></dl><p v-if="busy" role="status">Загрузка сохранённых снимков…</p>
 <template v-if="views.includes('discovery')"><h3>Актуальность Inventory</h3><div class="table-wrap"><table class="table"><thead><tr><th>Коллектор</th><th>Состояние</th><th>Наблюдение</th><th>Полнота</th></tr></thead><tbody><tr v-for="s in scopes" :key="s.name" :data-testid="'os-scope-'+s.name"><td>{{target.provider}}.{{s.name}}</td><td>{{s.error||(states[s.data?.state||'']||s.data?.state||'—')}}</td><td>{{s.data?.observed_at||'—'}}</td><td>{{s.data?.snapshot?s.data.snapshot.complete?'Полный':'Неполный':'—'}}</td></tr></tbody></table></div><button class="btn" @click="emit('open','discovery')">Открыть Inventory</button><h3>Последние запуски</h3><UiAlert :message="runError"/><div class="table-wrap"><table class="table"><thead><tr><th>Коллектор / операция</th><th>Статус</th><th>Время</th></tr></thead><tbody><tr v-for="r in runs" :key="r.id"><td>{{r.collector_name||r.operation}}</td><td>{{r.status}} {{r.error_code||''}}</td><td>{{r.created_at}}</td></tr></tbody></table></div><p v-if="!runs.length&&!busy&&!runError">Запусков нет.</p><button class="btn" @click="emit('open','plans')">Планы и история</button></template>
 <template v-if="views.includes('backups')"><h3>Последняя резервная копия</h3><UiAlert :message="backupError"/><p v-if="backup" data-testid="os-latest-backup">v{{backup.version}} · {{backup.observed_at}} · {{backup.byte_size}} B</p><p v-else-if="!busy&&!backupError">Резервных копий нет.</p><button class="btn" @click="emit('open','backups')">История / diff / экспорт</button></template>
 </section></template>
