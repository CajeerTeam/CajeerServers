<script setup lang="ts">
import { apiError } from '~/composables/useApi'
const props=defineProps<{targetId:string}>()
const api=useApi()
type Series={id:string;metric:string;kind:'gauge'|'counter';unit:string|null;resource_external_id:string;target_version:number;labels:Record<string,string>}
type Point={timestamp:string;value:string}
const series=ref<Series[]>([]),points=ref<Point[]>([]),selected=ref(''),metric=ref(''),offset=ref(0),more=ref(false),busy=ref(false),error=ref('')
const hours=ref(1),aggregation=ref('avg'),step=ref(60)
const path=`/connectors/targets/${props.targetId}/metric-series`
let sequence=0, disposed=false
const active=computed(()=>series.value.find(s=>s.id===selected.value))
const chart=computed(()=>{const values=points.value.map(p=>Number(p.value));if(values.length<2||values.some(v=>!Number.isFinite(v)))return '';const lo=Math.min(...values),hi=Math.max(...values);const times=points.value.map(p=>Date.parse(p.timestamp));const first=times[0],last=times[times.length-1];return values.map((v,i)=>`${10+580*((times[i]-first)/Math.max(1,last-first))},${130-110*((v-lo)/Math.max(1e-20,hi-lo))}`).join(' ')})
async function load(page=0){const seq=++sequence;busy.value=true;error.value='';points.value=[];try{const data=await api.get<{data:Series[]}>(`${path}?${new URLSearchParams({limit:'100',offset:String(page),...(metric.value.trim()?{metric:metric.value.trim()}:{})})}`);if(disposed||seq!==sequence)return;series.value=data.data;offset.value=page;more.value=series.value.length===100;selected.value=series.value[0]?.id||''}catch(e){if(!disposed&&seq===sequence)error.value=apiError(e).message}finally{if(seq===sequence)busy.value=false}}
async function query(){if(!active.value)return;const seq=++sequence;busy.value=true;error.value='';points.value=[];const to=new Date();const from=new Date(to.getTime()-hours.value*3600000);try{const data=await api.get<{data:{points:Point[]}}>(`${path}/${encodeURIComponent(selected.value)}/samples?${new URLSearchParams({from:from.toISOString(),to:to.toISOString(),aggregation:aggregation.value,step:String(step.value)})}`);if(!disposed&&seq===sequence)points.value=data.data.points}catch(e){if(!disposed&&seq===sequence)error.value=apiError(e).message}finally{if(seq===sequence)busy.value=false}}
watch(selected,()=>{points.value=[];if(active.value?.kind!=='counter'&&aggregation.value==='rate')aggregation.value='avg'})
onMounted(()=>load());onBeforeUnmount(()=>{disposed=true;sequence++})
</script>
<template>
  <section class="panel" data-testid="connector-metrics">
    <h2>Временные ряды</h2><p class="muted">Метрики из планов *.metrics. Числа в таблице сохраняют точность исходных счётчиков; график — приближённое представление.</p>
    <p v-if="error" role="alert">{{error}}</p>
    <form class="form-row" @submit.prevent="load(0)"><label>Имя метрики (точное)<input v-model="metric" maxlength="128" /></label><button class="btn" :disabled="busy">Найти / обновить</button></form>
    <p v-if="!series.length&&!busy">Рядов нет. Выполните план сбора метрик с операцией inspect.</p>
    <template v-if="series.length">
      <form class="form-row" @submit.prevent="query"><label>Ряд<select v-model="selected" :disabled="busy"><option v-for="s in series" :key="s.id" :value="s.id">{{s.metric}} · {{s.resource_external_id||'endpoint'}} · v{{s.target_version}} · {{JSON.stringify(s.labels)}}</option></select></label>
      <label>Период<select v-model.number="hours"><option :value="1">1 час</option><option :value="24">24 часа</option><option :value="168">7 дней</option><option :value="720">30 дней</option></select></label>
      <label>Агрегация<select v-model="aggregation"><option value="raw">Raw</option><option value="avg">Среднее</option><option value="min">Минимум</option><option value="max">Максимум</option><option value="count">Количество</option><option v-if="active?.kind==='counter'" value="rate">Rate / сек</option></select></label>
      <label>Шаг, сек<input v-model.number="step" type="number" min="1" max="86400" required /></label><button class="btn primary" :disabled="busy||!selected">Запросить</button></form>
      <div><button type="button" class="btn" :disabled="busy||offset===0" @click="load(Math.max(0,offset-100))">Назад</button> <button type="button" class="btn" :disabled="busy||!more" @click="load(offset+100)">Далее</button></div>
      <p v-if="points.length">{{points.length}} точек · {{active?.unit||'без единицы'}}</p>
      <svg v-if="chart" viewBox="0 0 600 150" width="100%" role="img" aria-label="График метрики"><polyline :points="chart" fill="none" stroke="currentColor" stroke-width="2"/></svg>
      <div v-if="points.length" class="table-wrap"><table class="table"><thead><tr><th>Время</th><th>Значение</th></tr></thead><tbody><tr v-for="p in points" :key="p.timestamp"><td>{{p.timestamp}}</td><td>{{p.value}}</td></tr></tbody></table></div>
    </template>
  </section>
</template>
