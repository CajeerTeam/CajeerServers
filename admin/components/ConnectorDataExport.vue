<script setup lang="ts">
import { apiError } from '~/composables/useApi'
const props=defineProps<{targetId:string;canIngestion:boolean}>(),api=useApi()
type Kind='metrics'|'events'|'observations'|'runs'
type Job={id:string;kind:Kind;format:string;status:string;created_at:string;record_count:number|null;artifact_bytes:number|null;error_code:string|null}
const path=`/connectors/targets/${props.targetId}/data-exports`
const form=reactive({kind:'metrics' as Kind,format:'json',from:new Date(Date.now()-3600000).toISOString(),to:new Date().toISOString(),series_id:''})
const formats=ref<{format:string;kinds:Kind[]}[]>([]),jobs=ref<Job[]>([]),busy=ref(false),error=ref(''),offset=ref(0),key=ref(''),requestBody=ref('')
let disposed=false
const available=computed(()=>formats.value.filter(f=>f.kinds.includes(form.kind)))
watch(()=>form.kind,()=>{if(!available.value.some(x=>x.format===form.format))form.format='json'})
async function perform(fn:()=>Promise<void>){if(busy.value||disposed)return;busy.value=true;error.value='';try{await fn()}catch(e){if(!disposed)error.value=apiError(e).message}finally{if(!disposed)busy.value=false}}
async function refresh(){const {data}=await api.get<{data:{formats:typeof formats.value;jobs:Job[]}}>(`${path}?limit=50&offset=${offset.value}`);if(!disposed){formats.value=data.formats;jobs.value=data.jobs}}
function load(){return perform(refresh)}
async function create(){await perform(async()=>{const body={kind:form.kind,format:form.format,from:form.from,to:form.to,...(form.kind==='metrics'&&form.series_id?{series_id:form.series_id}:{})};const serialized=JSON.stringify(body);if(serialized!==requestBody.value||!key.value){key.value=crypto.randomUUID();requestBody.value=serialized}await api.write('POST',path,body,true,{'Idempotency-Key':key.value});key.value='';requestBody.value='';await refresh()})}
async function cancel(j:Job){if(!confirm('Отменить ожидающую выгрузку?'))return;await perform(async()=>{await api.write('POST',`${path}/${j.id}/cancel`);await refresh()})}
const base=useRuntimeConfig().public.apiBase||'/api/v1'
function download(j:Job){return `${base}${path}/${j.id}/download`}
function page(delta:number){offset.value=Math.max(0,offset.value+delta);void load()}
onMounted(load);onBeforeUnmount(()=>{disposed=true})
</script>
<template>
<section class="panel" data-testid="connector-data-export">
  <h2>Экспорт данных</h2><p class="muted">Фоновая выгрузка сохранённых данных. До 30 дней, 4 МиБ на файл. OpenMetrics — последние значения каждого ряда в выбранном диапазоне.</p>
  <p v-if="error" role="alert">{{error}}</p>
  <form class="form-row" @submit.prevent="create">
    <label>Данные<select v-model="form.kind"><option value="metrics">Метрики</option><option v-if="canIngestion" value="events">События</option><option v-if="canIngestion" value="observations">Наблюдения</option><option value="runs">Результаты запусков</option></select></label>
    <label>Формат<select v-model="form.format"><option v-for="f in available" :key="f.format" :value="f.format">{{f.format}}</option></select></label>
    <label>От (UTC)<input v-model="form.from" required /></label><label>До (UTC)<input v-model="form.to" required /></label>
    <label v-if="form.kind==='metrics'">UUID ряда (необязательно)<input v-model.trim="form.series_id" /></label>
    <button class="btn primary" :disabled="busy||!available.length">Создать выгрузку</button>
  </form>
  <button class="btn" :disabled="busy" @click="load">Обновить</button>
  <div class="table-wrap"><table class="table"><thead><tr><th>Тип / формат</th><th>Статус</th><th>Результат</th><th>Действия</th></tr></thead><tbody><tr v-for="j in jobs" :key="j.id"><td>{{j.kind}} · {{j.format}}<br>{{j.created_at}}</td><td>{{j.status}} {{j.error_code||''}}</td><td>{{j.record_count??'—'}} записей · {{j.artifact_bytes??'—'}} байт</td><td><a v-if="j.status==='succeeded'" class="btn" :href="download(j)">Скачать</a><button v-if="j.status==='queued'" class="btn" :disabled="busy" @click="cancel(j)">Отменить</button></td></tr></tbody></table></div>
  <button class="btn" :disabled="busy||offset===0" @click="page(-50)">Назад</button> <button class="btn" :disabled="busy||jobs.length<50" @click="page(50)">Далее</button>
</section>
</template>
