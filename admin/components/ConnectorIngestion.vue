<script setup lang="ts">
import { apiError } from '~/composables/useApi'
import {usableGrant,type LifecycleTarget,type LifecycleBinding,type ServiceGrant} from '~/composables/connectorLifecycle'
const props=defineProps<{targetId:string;canManage:boolean;target?:LifecycleTarget;binding?:LifecycleBinding|null}>(), api=useApi(),auth=useAuth()
type Kind='metrics'|'events'|'observations'
type Source={id:string;name:string;kind:Kind;grant_id:string;actor_id:string;key_version:number;revoked_at:string|null;secret?:string}
type Batch={id:string;source_id:string;batch_id:string;kind:Kind;status:'queued'|'succeeded'|'failed';attempts:number;dispatch_count:number;received_at:string;error_code:string|null;result:unknown;payload?:unknown;history?:unknown}
type EventRow={id:string;source_id:string;event_id:string;type:string;severity:string;message:string;observed_at:string}
const sources=ref<Source[]>([]),batches=ref<Batch[]>([]),events=ref<EventRow[]>([]),detail=ref<Batch|null>(null),error=ref(''),busy=ref(false),secret=ref(''),secretSource=ref(''),showSecret=ref(false),batchOffset=ref(0),eventOffset=ref(0)
const form=reactive({name:'',kind:'metrics' as Kind,grant_id:''})
const path=`/connectors/targets/${props.targetId}`
const manual=reactive({source:'',format:'json',batch_id:'',content:''})
const preview=ref<{confirmation:string;kind:Kind;count:number;records:unknown[]}|null>(null)
const manualResult=ref(''),validationErrors=ref<{field:string;message:string}[]>([])
const canImport=computed(()=>props.canManage&&auth.can('ingestion.write'))
const ownSources=computed(()=>sources.value.filter(s=>!s.revoked_at&&s.actor_id===auth.user.value?.id))
let timer:ReturnType<typeof setInterval>|undefined
const liveGrants=ref<ServiceGrant[]>([])
const availableGrants=computed(()=>liveGrants.value.filter(g=>g.operation==='inspect'&&!g.interval_seconds&&(props.target?usableGrant(g,props.target,auth.user.value?.id||'',props.binding||null,Date.now()):g.actor_id===auth.user.value?.id&&!g.revoked_at&&!g.collection_plan_id&&Date.parse(g.expires_at)>Date.now())))
watch(manual,()=>{preview.value=null;manualResult.value='';validationErrors.value=[]},{deep:true,flush:'sync'})
async function importFile(event:Event){const file=(event.target as HTMLInputElement).files?.[0];if(!file)return;await perform(async()=>{if(file.size>262144){error.value='Файл превышает 256 КиБ';return;}manual.content=await file.text();manual.format=file.name.toLowerCase().endsWith('.csv')?'csv':'json';if(!manual.batch_id)manual.batch_id=crypto.randomUUID()})}
async function previewImport(){await perform(async()=>{preview.value=null;const snapshot=JSON.stringify(manual);const {data}=await api.write<{data:NonNullable<typeof preview.value>}>('POST',`${path}/ingestion-sources/${manual.source}/imports/preview`,{format:manual.format,batch_id:manual.batch_id,content:manual.content});if(!disposed&&snapshot===JSON.stringify(manual))preview.value=data})}
async function applyImport(){if(!preview.value||!confirm('Поставить проверенный импорт в очередь?'))return;await perform(async()=>{const {data}=await api.write<{data:{id:string;status:string;deduplicated:boolean}}>('POST',`${path}/ingestion-sources/${manual.source}/imports`,{format:manual.format,batch_id:manual.batch_id,content:manual.content,confirmation:preview.value!.confirmation});if(disposed)return;manualResult.value=`${data.id} · ${data.status}${data.deduplicated?' · повтор':''}`;await refresh()})}
let disposed=false
function clearSecret(){secret.value='';secretSource.value='';showSecret.value=false}
async function perform(fn:()=>Promise<void>){if(busy.value||disposed)return;busy.value=true;error.value='';validationErrors.value=[];try{await fn()}catch(e){if(!disposed){const failure=apiError(e);error.value=failure.message+(failure.code?' ('+failure.code+')':'');validationErrors.value=failure.errors||[]}}finally{if(!disposed)busy.value=false}}
async function refresh(){const [s,b,e]=await Promise.all([api.get<{data:Source[]}>(`${path}/ingestion-sources`),api.get<{data:Batch[]}>(`${path}/ingestion-batches?limit=50&offset=${batchOffset.value}`),api.get<{data:EventRow[]}>(`${path}/data-events?limit=50&offset=${eventOffset.value}`)]);if(disposed)return;sources.value=s.data;batches.value=b.data;events.value=e.data}
async function load(){await perform(async()=>{await refresh();if(props.canManage&&auth.can('connectors.credentials.read')){const {data}=await api.get<{data:typeof liveGrants.value}>(`${path}/service-grants`);if(!disposed)liveGrants.value=data}})}
async function poll(){if(disposed||busy.value||document.hidden)return;busy.value=true;try{await refresh();if(detail.value){const id=detail.value.id;const {data}=await api.get<{data:Batch}>(`${path}/ingestion-batches/${id}`);if(!disposed&&detail.value?.id===id)detail.value=data}}catch(e){if(!disposed)error.value=apiError(e).message}finally{if(!disposed)busy.value=false}}
async function create(){await perform(async()=>{clearSecret();const {data}=await api.write<{data:Source}>('POST',`${path}/ingestion-sources`,{...form});if(disposed)return;secret.value=data.secret||'';secretSource.value=data.id;form.name='';await refresh()})}
async function change(s:Source,rotate:boolean){if(!confirm(rotate?'Сменить ключ подписи? Старый ключ перестанет работать.':'Отозвать источник? Ожидающие batches не смогут записать данные.'))return;await perform(async()=>{clearSecret();if(rotate){const {data}=await api.write<{data:Source}>('POST',`${path}/ingestion-sources/${s.id}/rotate`);if(disposed)return;secret.value=data.secret||'';secretSource.value=s.id}else await api.write('DELETE',`${path}/ingestion-sources/${s.id}`);if(!disposed)await refresh()})}
async function inspect(b:Batch){await perform(async()=>{const {data}=await api.get<{data:Batch}>(`${path}/ingestion-batches/${b.id}`);if(!disposed)detail.value=data})}
async function redrive(b:Batch){if(!confirm('Повторить обработку сохранённого batch?'))return;await perform(async()=>{await api.write('POST',`${path}/ingestion-batches/${b.id}/redrive`);if(disposed)return;detail.value=null;await refresh()})}
function page(kind:'batch'|'event',delta:number){if(busy.value)return;const offset=kind==='batch'?batchOffset:eventOffset;offset.value=Math.max(0,Math.min(10000,offset.value+delta));void load()}
onMounted(()=>{void load();timer=setInterval(()=>void poll(),5000)});onBeforeUnmount(()=>{disposed=true;if(timer)clearInterval(timer);clearSecret();detail.value=null})
</script>
<template>
  <section class="panel" data-testid="connector-ingestion">
    <h2>Внешние данные</h2><p class="muted">Подписанные push-batches метрик, событий и кандидатов Inventory. Источник требует отдельного действующего inspect service grant без расписания.</p>
    <p v-if="error" role="alert">{{error}}</p><ul v-if="validationErrors.length" aria-label="Ошибки записей"><li v-for="(e,i) in validationErrors" :key="i">{{e.field}}: {{e.message}}</li></ul>
    <form v-if="canImport" class="form-row" @submit.prevent="create">
      <label>Имя<input v-model.trim="form.name" required maxlength="120" /></label>
      <label>Тип<select v-model="form.kind"><option value="metrics">Метрики</option><option value="events">События</option><option value="observations">Наблюдения</option></select></label>
      <label>Inspect grant<select v-if="auth.can('connectors.credentials.read')" v-model="form.grant_id" required><option value="">Выберите разрешение</option><option v-for="g in availableGrants" :key="g.id" :value="g.id">{{g.id}} · {{g.expires_at}}</option></select><input v-else v-model.trim="form.grant_id" required pattern="[0-9a-fA-F-]{36}" /></label>
      <button class="btn primary" :disabled="busy||(auth.can('connectors.credentials.read')&&!availableGrants.some(g=>g.id===form.grant_id))">Создать источник</button>
    </form>
    <div v-if="secret" role="status"><p>Одноразовый ключ для {{secretSource}}. Сохраните вне приложения.</p><p><code>{{showSecret?secret:'••••••••••••••••'}}</code></p><button class="btn" @click="showSecret=!showSecret">{{showSecret?'Скрыть':'Показать'}}</button> <button class="btn" @click="clearSecret">Закрыть</button></div>
    <button class="btn" :disabled="busy" @click="load">Обновить</button>
    <h3>Источники</h3><div class="table-wrap"><table class="table"><thead><tr><th>Источник / тип</th><th>URL / состояние</th><th>Действия</th></tr></thead><tbody><tr v-for="s in sources" :key="s.id"><td>{{s.name}} · {{s.kind}}<br><code>{{s.id}}</code></td><td><code>/api/v1/connectors/ingestion/{{s.id}}/batches</code><br>{{s.revoked_at?'Отозван':'Ключ v'+s.key_version}}</td><td><template v-if="canManage&&!s.revoked_at"><button v-if="canImport&&s.actor_id===auth.user.value?.id" class="btn" :disabled="busy" @click="change(s,true)">Ротация</button> <button class="btn" :disabled="busy" @click="change(s,false)">Отозвать</button></template></td></tr></tbody></table></div>
    <details v-if="canImport"><summary>Ручной импорт JSON / CSV</summary><p class="muted">Можно загрузить JSON-выгрузку метрик, событий или наблюдений. Источник назначения назначается сервером; разные исходные ряды не объединяются. До 256 КиБ / 1000 записей.</p>
      <p class="muted">Выберите свой активный источник. JSON — массив записей или нормализованный результат скрипта, CSV — заголовок и строки; timestamps обязательны. После проверки подтвердите импорт.</p>
      <form class="form-row" @submit.prevent="previewImport" data-testid="manual-import-form">
        <label>Источник<select v-model="manual.source" required><option value="" disabled>Выберите источник</option><option v-for="s in ownSources" :key="s.id" :value="s.id">{{s.name}} · {{s.kind}}</option></select></label>
        <label>Формат<select v-model="manual.format"><option>json</option><option>csv</option></select></label>
        <label>ID batch<input v-model="manual.batch_id" required maxlength="128" /></label>
        <label>Файл<input type="file" accept=".json,.csv" :disabled="busy" @change="importFile" /></label>
        <label>Содержимое<textarea v-model="manual.content" required maxlength="262144" rows="5" /></label>
        <button class="btn" :disabled="busy||!manual.source">Проверить</button>
      </form>
      <div v-if="preview" data-testid="manual-import-preview"><p>Записей: {{preview.count}} · {{preview.kind}}</p><pre style="max-height:300px;overflow:auto;white-space:pre-wrap">{{JSON.stringify(preview.records,null,2)}}</pre><button class="btn primary" :disabled="busy" @click="applyImport">Подтвердить импорт</button></div>
      <p v-if="manualResult" role="status">{{manualResult}}</p>
    </details>
    <h3>История batches</h3><div class="table-wrap"><table class="table"><thead><tr><th>ID / тип</th><th>Состояние</th><th>Попытки</th><th>Действия</th></tr></thead><tbody><tr v-for="b in batches" :key="b.id" :data-testid="`ingestion-batch-${b.id}`"><td>{{b.batch_id}} · {{b.kind}}<br>{{b.received_at}}</td><td>{{b.status}} {{b.error_code||''}}</td><td>{{b.dispatch_count}} / {{b.attempts}}</td><td><button class="btn" :disabled="busy" @click="inspect(b)">Результат</button> <button v-if="canManage&&b.status==='failed'&&b.dispatch_count<10" class="btn" :disabled="busy" @click="redrive(b)">Redrive</button></td></tr></tbody></table></div>
    <button class="btn" :disabled="busy||batchOffset===0" @click="page('batch',-50)">Назад</button> <button class="btn" :disabled="busy||batches.length<50" @click="page('batch',50)">Далее</button>
    <div v-if="detail"><h3>{{detail.batch_id}} · {{detail.status}}</h3><button class="btn" @click="detail=null">Закрыть</button><pre style="max-height: 400px; overflow: auto; white-space: pre-wrap">{{JSON.stringify(detail,null,2)}}</pre></div>
    <h3>События</h3><div class="table-wrap"><table class="table"><thead><tr><th>Время</th><th>Тип / уровень</th><th>Сообщение</th></tr></thead><tbody><tr v-for="e in events" :key="e.id"><td>{{e.observed_at}}</td><td>{{e.type}} · {{e.severity}}</td><td>{{e.message}}</td></tr></tbody></table></div>
    <button class="btn" :disabled="busy||eventOffset===0" @click="page('event',-50)">Назад</button> <button class="btn" :disabled="busy||events.length<50" @click="page('event',50)">Далее</button>
  </section>
</template>
