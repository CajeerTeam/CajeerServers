<script setup lang="ts">
import {apiError} from '~/composables/useApi'
type Scope='system'|'software'|'services'|'storage'|'network'
type Row=Record<string,string|null>
type Snapshot={scope:Scope;complete:boolean;counts:Record<string,number>;sections:Record<string,Row[]>}
type Current={state:'available'|'stale'|'not_collected';observed_at:string|null;snapshot:Snapshot|null}
type Run={id:string;created_at:string;status:string;error_code:string|null;result?:{connection?:{linux_discovery?:Snapshot}}}
const props=defineProps<{targetId:string;profileConfigured:boolean}>()
const api=useApi(),path=`/connectors/targets/${props.targetId}`
const scopes:Scope[]=['system','software','services','storage','network']
const labels:Record<Scope,string>={system:'Система и оборудование',software:'Пакеты',services:'Службы',storage:'Диски и файловые системы',network:'Сеть'}
const scope=ref<Scope>('system'),current=ref<Current|null>(null),runs=ref<Run[]>([]),selected=ref<Run|null>(null),section=ref(''),search=ref(''),page=ref(0),offset=ref(0),error=ref(''),loading=ref(false)
const snapshot=computed(()=>selected.value?selected.value.result?.connection?.linux_discovery||null:current.value?.snapshot||null)
const sections=computed(()=>Object.keys(snapshot.value?.sections||{}))
const rows=computed(()=>snapshot.value?.sections[section.value]||[])
const columns=computed(()=>Object.keys(rows.value[0]||{}))
const filtered=computed(()=>{const q=search.value.trim().toLowerCase();return rows.value.filter(r=>!q||Object.values(r).some(v=>String(v??'').toLowerCase().includes(q)))})
const shown=computed(()=>filtered.value.slice(page.value*50,page.value*50+50))
watch([section,search],()=>{page.value=0});watch(snapshot,()=>{if(!sections.value.includes(section.value))section.value=sections.value[0]||'';page.value=0})
let seq=0,disposed=false
async function read<T>(suffix:string){return (await api.get<{data:T}>(path+suffix)).data}
async function refresh(next=offset.value){const n=++seq;loading.value=true;error.value='';try{const [s,h]=await Promise.all([read<Current>(`/linux-discovery?scope=${scope.value}`),read<Run[]>(`/runs?collector=ssh.${scope.value}&limit=20&offset=${next}`)]);if(!disposed&&seq===n){current.value=s;runs.value=h;offset.value=next}}catch(e){if(!disposed&&seq===n){error.value=apiError(e).message;current.value=null;runs.value=[];selected.value=null}}finally{if(!disposed&&seq===n)loading.value=false}}
async function history(id:string){const n=++seq;loading.value=true;error.value='';try{const r=await read<Run>('/runs/'+id);if(!disposed&&seq===n&&r.result?.connection?.linux_discovery?.scope===scope.value)selected.value=r}catch(e){if(!disposed&&seq===n)error.value=apiError(e).message}finally{if(!disposed&&seq===n)loading.value=false}}
watch(scope,()=>{selected.value=null;current.value=null;runs.value=[];search.value='';offset.value=0;void refresh(0)})
onMounted(()=>{void refresh(0)});onBeforeUnmount(()=>{disposed=true;seq++})
</script>
<template>
<section class="panel" data-testid="linux-discovery">
 <h2>Debian / Ubuntu — подробный Inventory</h2>
 <p v-if="!profileConfigured" role="alert">Настройте SSH-профиль доверия перед сбором.</p>
 <p class="muted">Пять независимых read-only планов Inspect. Базовый ssh.inventory Sync продолжает обновлять CMDB. Размеры в байтах; «—» означает неизвестное значение.</p>
 <div class="form-row"><label>Сборщик<select v-model="scope"><option v-for="s in scopes" :key="s" :value="s">{{labels[s]}} (ssh.{{s}})</option></select></label><button class="btn" :disabled="loading" @click="refresh()">Обновить</button><button v-if="selected" class="btn" @click="selected=null">Текущий снимок</button></div>
 <p v-if="error" role="alert">{{error}}</p><p v-if="loading" role="status">Загрузка…</p>
 <p v-if="selected">История: {{selected.created_at}} — не текущее состояние.</p><p v-else-if="current?.state==='stale'" role="alert">Профиль или credentials изменились. Нужен новый сбор.</p><p v-else-if="current?.state==='not_collected'">Снимок ещё не собран.</p>
 <template v-if="snapshot"><p v-if="!selected">Наблюдение: {{current?.observed_at}}</p>
 <div class="form-row"><label>Раздел<select v-model="section"><option v-for="s in sections" :key="s" :value="s">{{s}} ({{snapshot.counts[s]}})</option></select></label><label>Поиск<input v-model="search" maxlength="200"></label></div>
 <div class="table-wrap"><table class="table"><thead><tr><th v-for="c in columns" :key="c">{{c}}</th></tr></thead><tbody><tr v-for="(r,i) in shown" :key="i"><td v-for="c in columns" :key="c">{{r[c]??'—'}}</td></tr></tbody></table></div><p v-if="!shown.length">Нет записей.</p>
 <button class="btn" :disabled="page===0" @click="page--">Назад</button> <button class="btn" :disabled="(page+1)*50>=filtered.length" @click="page++">Далее</button> Найдено: {{filtered.length}}
 </template>
 <h3>История сборов</h3><div class="table-wrap"><table class="table"><thead><tr><th>Время</th><th>Статус</th><th>Снимок</th></tr></thead><tbody><tr v-for="r in runs" :key="r.id"><td>{{r.created_at}}</td><td>{{r.status}} {{r.error_code||''}}</td><td><button class="btn" :disabled="loading||r.status!=='succeeded'" @click="history(r.id)">Открыть</button></td></tr></tbody></table></div>
 <button class="btn" :disabled="loading||offset===0" @click="refresh(offset-20)">Назад</button> <button class="btn" :disabled="loading||runs.length<20||offset>=10000" @click="refresh(offset+20)">Далее</button>
</section>
</template>
