<script setup lang="ts">
import { apiError } from '~/composables/useApi'
type Scope='storage'|'templates'
type Value=string|number|boolean|null|number[]|{total:string|null;used:string|null;free:string|null}
type CatalogRow=Record<string,Value>
type Snapshot={complete:boolean;visibility:string;sections:Record<string,string>;counts:Record<string,number>;unknown_guest_disks?:number;unlinked_volumes?:number;unknown_guest_os?:number;storages?:CatalogRow[];nodes?:CatalogRow[];volumes?:CatalogRow[];os?:CatalogRow[];presets?:CatalogRow[];images?:CatalogRow[];isos?:CatalogRow[];guest_os_links?:CatalogRow[]}
type Current={state:'not_collected'|'stale'|'available';observed_at:string|null;snapshot:Snapshot|null}
type Run={id:string;created_at:string;status:string;error_code:string|null;result?:{connection?:{vmmanager_storage_discovery?:Snapshot;vmmanager_templates_discovery?:Snapshot}}|null}
const props=defineProps<{targetId:string;profileConfigured:boolean;scope:Scope}>()
const api=useApi(), path=`/connectors/targets/${props.targetId}`, collector=`vmmanager.${props.scope}`
const current=ref<Current|null>(null),runs=ref<Run[]>([]),selected=ref<Run|null>(null),error=ref(''),loading=ref(false),offset=ref(0),search=ref(''),page=ref(0)
const labels:Record<string,string>={storages:'Хранилища',nodes:'Ёмкость узлов',volumes:'Диски гостей',os:'Шаблоны ОС',presets:'Конфигурации VM',images:'Образы VM',isos:'ISO',guest_os_links:'ОС гостей'}
const fields:Record<string,string[]>={storages:['id','name','type','state','shared','cluster_ids','node_ids','capacity_mib','visible_disks'],nodes:['id','name','cluster_id','storage_ids','capacity_mib'],volumes:['key','guest_name','name','size_mib','storage_id','storage_name','storage_visible','node_id','cluster_id','type','bus','device'],os:['id','name','state','type','version','architecture','os_family','cluster_ids','node_ids','repository_id','enabled'],presets:['id','name','state','type','cpu_number','ram_mib','hdd_mib','cluster_ids','os_id'],images:['id','name','state','type','size_mib','os_id','cluster_ids','node_ids'],isos:['id','name','state','size_mib','repository_id','cluster_ids','node_ids'],guest_os_links:['guest_id','guest_name','node_id','cluster_id','os_id','os_name','os_visible']}
const tabs=props.scope==='storage'?['storages','nodes','volumes']:['os','presets','images','isos','guest_os_links']
const tab=ref(tabs[0]!),snapshot=computed(()=>selected.value?(props.scope==='storage'?selected.value.result?.connection?.vmmanager_storage_discovery:selected.value.result?.connection?.vmmanager_templates_discovery)||null:current.value?.snapshot||null)
const columns=computed(()=>fields[tab.value]||[])
const rows=computed(()=>{const s=snapshot.value;if(!s)return [];const raw=s[tab.value as keyof Snapshot];return Array.isArray(raw)?raw as CatalogRow[]:[]})
function value(v:Value|undefined):string{if(v===null||v===undefined)return '—';if(Array.isArray(v))return v.length?v.join(', '):'∅';if(typeof v==='object')return `Всего: ${v.total??'—'} / занято: ${v.used??'—'} / свободно: ${v.free??'—'}`;return String(v)}
const filtered=computed(()=>{const q=search.value.trim().toLowerCase();return rows.value.filter(r=>!q||columns.value.some(k=>value(r[k]).toLowerCase().includes(q)))})
const shown=computed(()=>filtered.value.slice(page.value*50,page.value*50+50))
watch([tab,search,selected],()=>{page.value=0});watch(snapshot,()=>{page.value=Math.min(page.value,Math.max(0,Math.ceil(filtered.value.length/50)-1))})
let disposed=false,seq=0,timer:ReturnType<typeof setInterval>|undefined
async function read<T>(suffix:string){return (await api.get<{data:T}>(path+suffix)).data}
async function refresh(next=offset.value){if(loading.value||disposed)return;const id=++seq;loading.value=true;error.value='';try{const [s,h]=await Promise.all([read<Current>(`/vmmanager-${props.scope}`),read<Run[]>(`/runs?collector=${collector}&limit=20&offset=${next}`)]);if(!disposed&&seq===id){current.value=s;runs.value=h;offset.value=next}}catch(e){if(!disposed&&seq===id){error.value=apiError(e).message;current.value=null;runs.value=[];selected.value=null}}finally{if(!disposed&&seq===id)loading.value=false}}
async function history(id:string){if(loading.value||disposed)return;const n=++seq;loading.value=true;error.value='';try{const r=await read<Run>('/runs/'+id);if(!disposed&&seq===n)selected.value=r}catch(e){if(!disposed&&seq===n){error.value=apiError(e).message;selected.value=null}}finally{if(!disposed&&seq===n)loading.value=false}}
onMounted(()=>{void refresh();timer=setInterval(()=>{if(!document.hidden&&!selected.value)void refresh()},15000)});onBeforeUnmount(()=>{disposed=true;seq++;if(timer)clearInterval(timer)})
</script>
<template>
  <section class="panel" :data-testid="`vmmanager-${scope}-discovery`">
    <h2>VMmanager: {{scope==='storage'?'Хранилища и диски':'Шаблоны и образы'}}</h2>
    <p v-if="!profileConfigured" role="alert">Для сбора настройте проверенный TLS-профиль VMmanager.</p>
    <p class="muted">План <code>{{collector}}</code>, Inspect. Размеры в MiB; неизвестные значения обозначены «—». Выделенный размер диска не равен физически занятой ёмкости.</p>
    <div class="inline-actions"><button class="btn" :disabled="loading" @click="refresh()">Обновить</button><button v-if="selected" class="btn" :disabled="loading" @click="selected=null">Текущий снимок</button></div>
    <p v-if="error" role="alert">{{error}}</p><p v-if="loading" role="status">Загрузка…</p>
    <p v-if="selected">Исторический запуск {{selected.created_at}} — не текущее состояние.</p><p v-else-if="current?.state==='stale'" role="alert">Подключение изменилось. Необходим новый сбор.</p><p v-else-if="current?.state==='not_collected'">Снимок ещё не получен.</p>
    <template v-if="snapshot">
      <p v-if="!selected">Наблюдение: {{current?.observed_at}}</p><p v-if="!snapshot.complete" role="alert">Неполный снимок: ограниченная видимость или отсутствующие связи/каталоги.</p>
      <p v-if="scope==='storage'">Неизвестные диски у {{snapshot.unknown_guest_disks}} гостей · Несопоставленные диски: {{snapshot.unlinked_volumes}}</p><p v-else>Гости без сведений об ОС: {{snapshot.unknown_guest_os}}</p>
      <p>Доступность API: <span v-for="(status,key) in snapshot.sections" :key="key">{{key}}: {{status}} · </span></p>
      <div class="form-row"><label>Раздел<select v-model="tab"><option v-for="key in tabs" :key="key" :value="key">{{labels[key]}} ({{snapshot.counts[key]??0}})</option></select></label><label>Поиск<input v-model="search" placeholder="Имя, ID, тип, кластер или узел"></label></div>
      <div class="table-wrap"><table class="table"><thead><tr><th v-for="key in columns" :key="key">{{key}}</th></tr></thead><tbody><tr v-for="(row,index) in shown" :key="String(row.key??row.id??row.guest_id??index)"><td v-for="key in columns" :key="key">{{value(row[key])}}</td></tr><tr v-if="!shown.length"><td :colspan="columns.length">Нет видимых записей в разделе. Проверьте статус API выше.</td></tr></tbody></table></div>
      <button class="btn" :disabled="page===0" @click="page--">Назад</button> <button class="btn" :disabled="(page+1)*50>=filtered.length" @click="page++">Далее</button> <span>Найдено: {{filtered.length}}</span>
    </template>
    <h3>История сборов</h3><div class="table-wrap"><table class="table"><thead><tr><th>Время</th><th>Статус</th><th>Снимок</th></tr></thead><tbody><tr v-for="r in runs" :key="r.id"><td>{{r.created_at}}</td><td>{{r.status}} {{r.error_code||''}}</td><td><button class="btn" :disabled="loading||r.status!=='succeeded'" @click="history(r.id)">Открыть</button></td></tr></tbody></table></div>
    <button class="btn" :disabled="loading||offset===0" @click="refresh(offset-20)">Назад</button> <button class="btn" :disabled="loading||runs.length<20||offset>=10000" @click="refresh(offset+20)">Далее</button>
  </section>
</template>
