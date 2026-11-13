<script setup lang="ts">
import { apiError } from '~/composables/useApi'
type Capacity={total:string|null;used:string|null}
type Node={id:number;external_id:string;name:string;cluster_id:number|null;cluster_visible:boolean;status:string;remote_state:string;maintenance_mode:boolean;ip_addresses:string[];cpu_number:string|null;ram_mib:Capacity|null;storage_mib:Capacity|null;host_count:string|null;host_limit:string|null}
type Cluster={id:number;external_id:string;name:string;virtualization_type:string;visible_nodes:number}
type Snapshot={complete:boolean;visibility:string;counts:{clusters:number;nodes:number;unlinked_nodes:number};clusters:Cluster[];nodes:Node[]}
type Current={state:'not_collected'|'stale'|'available';observed_at:string|null;snapshot:Snapshot|null}
type Run={id:string;created_at:string;status:string;operation:string;error_code:string|null;result?:{connection?:{vmmanager_cluster_discovery?:Snapshot}}|null}
const props=defineProps<{targetId:string;profileConfigured:boolean}>()
const api=useApi(),path=`/connectors/targets/${props.targetId}`
const current=ref<Current|null>(null),runs=ref<Run[]>([]),selected=ref<Run|null>(null),offset=ref(0),error=ref(''),loading=ref(false),filter=ref(''),clusterFilter=ref('')
let disposed=false,sequence=0,timer:ReturnType<typeof setInterval>|undefined
const snapshot=computed(()=>selected.value?selected.value.result?.connection?.vmmanager_cluster_discovery||null:current.value?.snapshot||null)
const filtered=computed(()=>{const q=filter.value.trim().toLowerCase();return (snapshot.value?.nodes||[]).filter(n=>(!clusterFilter.value||String(n.cluster_id)===clusterFilter.value)&&(!q||[n.name,String(n.id),...n.ip_addresses].some(v=>v.toLowerCase().includes(q))))})
function capacity(c:Capacity|null){return c?`${c.used??'—'} / ${c.total??'—'} MiB`:'—'}
async function read<T>(suffix:string){return (await api.get<{data:T}>(path+suffix)).data}
async function refresh(page=offset.value){if(loading.value||disposed)return;const seq=++sequence;loading.value=true;error.value='';try{const [state,history]=await Promise.all([read<Current>('/vmmanager-cluster'),read<Run[]>(`/runs?collector=vmmanager.cluster&limit=20&offset=${page}`)]);if(!disposed&&seq===sequence){current.value=state;runs.value=history;offset.value=page}}catch(e){if(!disposed&&seq===sequence){error.value=apiError(e).message;current.value=null;runs.value=[];selected.value=null}}finally{if(!disposed&&seq===sequence)loading.value=false}}
async function history(id:string){if(loading.value||disposed)return;const seq=++sequence;loading.value=true;error.value='';try{const row=await read<Run>('/runs/'+id);if(!disposed&&seq===sequence){selected.value=row;clusterFilter.value=''}}catch(e){if(!disposed&&seq===sequence){error.value=apiError(e).message;selected.value=null}}finally{if(!disposed&&seq===sequence)loading.value=false}}
onMounted(()=>{void refresh();timer=setInterval(()=>{if(!document.hidden&&!selected.value)void refresh()},15000)});onBeforeUnmount(()=>{disposed=true;sequence++;if(timer)clearInterval(timer)})
</script>
<template>
  <section class="panel" data-testid="vmmanager-cluster-discovery">
    <h2>VMmanager: кластеры и физические узлы</h2>
    <p v-if="!profileConfigured" role="alert">Для сбора настройте проверенный TLS-профиль VMmanager.</p>
    <p class="muted">Создайте план <code>vmmanager.cluster</code> в разделе «Планы и запуски». Inspect сохраняет снимок, Sync также обновляет Inventory и обнаруженные связи кластеров с узлами.</p>
    <div class="inline-actions"><button class="btn" :disabled="loading" @click="refresh()">Обновить</button><button v-if="selected" class="btn" :disabled="loading" @click="selected=null;clusterFilter=''">Текущий снимок</button></div>
    <p v-if="error" role="alert">{{error}}</p><p v-if="loading" role="status">Загрузка…</p>
    <p v-if="selected" class="muted">Исторический запуск {{selected.created_at}}. Не является текущим состоянием.</p>
    <p v-else-if="current?.state==='stale'" role="alert">Профиль или credentials изменились. Необходим новый сбор.</p>
    <p v-else-if="current?.state==='not_collected'">Снимок ещё не получен.</p>
    <template v-if="snapshot">
      <p v-if="!selected">Наблюдение: {{current?.observed_at}}</p>
      <p>Кластеры: {{snapshot.counts.clusters}} · Узлы: {{snapshot.counts.nodes}} · Без видимого кластера: {{snapshot.counts.unlinked_nodes}}</p>
      <p v-if="!snapshot.complete" role="alert">Ограниченная видимость: снимок не является полным.</p>
      <div class="table-wrap"><table class="table"><thead><tr><th>Кластер</th><th>Виртуализация</th><th>Видимые узлы</th></tr></thead><tbody><tr v-for="c in snapshot.clusters" :key="c.id"><td>{{c.name}} (#{{c.id}})</td><td>{{c.virtualization_type}}</td><td>{{c.visible_nodes}}</td></tr></tbody></table></div>
      <div class="form-row"><label>Поиск узла<input v-model="filter" placeholder="Имя, ID или IP"></label><label>Кластер<select v-model="clusterFilter"><option value="">Все</option><option v-for="c in snapshot.clusters" :key="c.id" :value="String(c.id)">{{c.name}}</option></select></label></div>
      <div class="table-wrap"><table class="table"><thead><tr><th>Узел / IP</th><th>Кластер</th><th>Состояние</th><th>CPU</th><th>RAM / Storage (used / total)</th><th>Гости / лимит</th></tr></thead><tbody><tr v-for="n in filtered" :key="n.id"><td><strong>{{n.name}}</strong><br>#{{n.id}}<br>{{n.ip_addresses.join(', ')||'—'}}</td><td>{{snapshot.clusters.find(c=>c.id===n.cluster_id)?.name||'Не виден'}}<br>{{n.cluster_id??'—'}}</td><td>{{n.status}}<br>{{n.remote_state}}</td><td>{{n.cpu_number??'—'}}</td><td>RAM: {{capacity(n.ram_mib)}}<br>Storage: {{capacity(n.storage_mib)}}</td><td>{{n.host_count??'—'}} / {{n.host_limit==='-1'?'без лимита':n.host_limit??'—'}}</td></tr><tr v-if="!filtered.length"><td colspan="6">Нет подходящих узлов.</td></tr></tbody></table></div>
    </template>
    <h3>История сборов</h3><div class="table-wrap"><table class="table"><thead><tr><th>Время</th><th>Операция / статус</th><th>Снимок</th></tr></thead><tbody><tr v-for="r in runs" :key="r.id"><td>{{r.created_at}}</td><td>{{r.operation}} · {{r.status}} {{r.error_code||''}}</td><td><button class="btn" :disabled="loading||r.status!=='succeeded'" @click="history(r.id)">Открыть</button></td></tr></tbody></table></div>
    <button class="btn" :disabled="loading||offset===0" @click="refresh(offset-20)">Назад</button> <button class="btn" :disabled="loading||runs.length<20||offset>=10000" @click="refresh(offset+20)">Далее</button>
  </section>
</template>
