<script setup lang="ts">
import { apiError } from '~/composables/useApi'
type Details={pve_version?:string;uptime_seconds?:number;cpu_utilization?:number;cpu?:{model?:string;logical_cpus?:number;cores?:number;sockets?:number};memory_bytes?:Record<string,string>;swap_bytes?:Record<string,string>;rootfs_bytes?:Record<string,string>}
type Node={name:string;external_id:string;node_id:number|null;online:boolean|null;local:boolean;ip?:string;votes?:number;status:string;visible_in_nodes:boolean;details_state:string;details:Details|null;last_details?:Details|null;last_details_observed_at?:string|null}
type Snapshot={cluster:{name:string;quorate:boolean;reported_nodes?:number;config_version?:number;expected_votes?:number;total_votes?:number;votes?:number}|null;standalone:boolean;member_count:number;visible_node_count:number;reported_node_count_mismatch:boolean;details_complete:boolean;nodes:Node[]}
type Current={state:'not_collected'|'stale'|'available';observed_at:string|null;snapshot:Snapshot|null}
type Run={id:string;status:string;created_at:string;finished_at:string|null;operation:string;error_code:string|null;result?:{connection?:{cluster_discovery?:Snapshot};collection?:{observed_at:string}}|null}
const props=defineProps<{targetId:string;profileConfigured:boolean}>()
const api=useApi(),path=`/connectors/targets/${props.targetId}`
const current=ref<Current|null>(null),runs=ref<Run[]>([]),selected=ref<Run|null>(null),offset=ref(0),error=ref(''),loading=ref(false)
let disposed=false,sequence=0,timer:ReturnType<typeof setInterval>|undefined
const states:Record<string,string>={available:'Получены',not_visible:'Не виден в списке узлов',offline:'Узел offline',status_uncertain:'Неопределённое состояние',permission_denied:'Недостаточно прав',unavailable:'Недоступен',rate_limited:'Ограничение API',budget_exhausted:'Лимит времени',no_data:'Нет данных'}
const snapshot=computed(()=>selected.value?selected.value.result?.connection?.cluster_discovery||null:current.value?.snapshot||null)
function size(v:string|undefined){if(v===undefined)return '—';return new Intl.NumberFormat('ru',{maximumFractionDigits:2}).format(Number(v)/1073741824)+' GiB'}
function usage(d:Record<string,string>|undefined){return d?`${size(d.used)} / ${size(d.total)}`:'—'}
async function read<T>(suffix:string){return (await api.get<{data:T}>(path+suffix)).data}
async function refresh(page=offset.value){if(loading.value||disposed)return;const seq=++sequence;loading.value=true;error.value='';try{const [state,history]=await Promise.all([read<Current>('/proxmox-cluster'),read<Run[]>(`/runs?collector=proxmox.cluster&limit=20&offset=${page}`)]);if(!disposed&&seq===sequence){current.value=state;runs.value=history;offset.value=page}}catch(e){if(!disposed&&seq===sequence){error.value=apiError(e).message;current.value=null;runs.value=[];selected.value=null}}finally{if(!disposed&&seq===sequence)loading.value=false}}
async function history(id:string){if(loading.value||disposed)return;const seq=++sequence;loading.value=true;error.value='';try{const row=await read<Run>('/runs/'+id);if(!disposed&&seq===sequence)selected.value=row}catch(e){if(!disposed&&seq===sequence){error.value=apiError(e).message;selected.value=null}}finally{if(!disposed&&seq===sequence)loading.value=false}}
onMounted(()=>{void refresh();timer=setInterval(()=>{if(!document.hidden&&!selected.value)void refresh()},15000)});onBeforeUnmount(()=>{disposed=true;sequence++;if(timer)clearInterval(timer)})
</script>
<template>
  <section class="panel" data-testid="proxmox-cluster-discovery">
    <h2>Proxmox: кластер и узлы</h2>
    <p v-if="!profileConfigured" role="alert">Для нового сбора настройте проверенный TLS-профиль в настройках подключения.</p>
    <p class="muted">Запустите коллектор <code>proxmox.cluster</code> в разделе «Планы и запуски». Inspect сохраняет снимок, Sync также синхронизирует кластер и узлы с Inventory.</p>
    <div class="inline-actions"><button class="btn" :disabled="loading" @click="refresh()">Обновить</button><button v-if="selected" class="btn" :disabled="loading" @click="selected=null">Текущий снимок</button></div>
    <p v-if="error" role="alert">{{error}}</p><p v-if="loading" role="status">Загрузка…</p>
    <p v-if="selected" class="muted">Исторический запуск {{selected.created_at}} · {{selected.status}}. Не является текущим состоянием.</p>
    <p v-else-if="current?.state==='stale'" role="alert">Конфигурация или учётные данные изменились. Нужен новый сбор; старые данные доступны в истории.</p>
    <p v-else-if="current?.state==='not_collected'">Снимок ещё не получен.</p>
    <template v-if="snapshot">
      <h3>{{snapshot.cluster?.name||'Standalone PVE'}}</h3>
      <p>{{snapshot.cluster?(snapshot.cluster.quorate?'Quorum: есть':'Quorum: потерян'):'Quorum: не применим'}} · Участников: {{snapshot.member_count}} · Видимых узлов: {{snapshot.visible_node_count}}</p>
      <p v-if="!selected" class="muted">Наблюдение: {{current?.observed_at}}</p>
      <p v-if="snapshot.cluster?.config_version!==undefined">Версия конфигурации: {{snapshot.cluster.config_version}}</p>
      <p v-if="snapshot.cluster?.expected_votes!==undefined||snapshot.cluster?.total_votes!==undefined">Голоса: {{snapshot.cluster.total_votes??'—'}} / {{snapshot.cluster.expected_votes??'—'}}</p>
      <p v-if="snapshot.reported_node_count_mismatch" role="alert">Количество участников в ответе отличается от объявленного кластером.</p>
      <p class="muted">Membership — только сообщённый API состав. Отсутствие ресурса не считается удалением. {{snapshot.details_complete?'Детали получены для всех сообщённых узлов.':'Часть деталей недоступна.'}}</p>
      <div class="table-wrap"><table class="table"><thead><tr><th>Узел</th><th>Состояние</th><th>CPU</th><th>RAM / Swap / RootFS</th><th>PVE / uptime</th></tr></thead><tbody>
        <tr v-for="n in snapshot.nodes" :key="n.external_id"><td><strong>{{n.name}}</strong><br>ID: {{n.node_id??'—'}}<br>{{n.ip||''}}<br><small v-if="n.local">Локальный endpoint</small></td><td>{{n.status}}<br>{{states[n.details_state]||n.details_state}}<br><small v-if="!n.details&&n.last_details">Последние известные данные: {{n.last_details_observed_at}}</small></td>
          <td>{{(n.details||n.last_details)?.cpu?.model||'—'}}<br>{{(n.details||n.last_details)?.cpu?.logical_cpus??'—'}} logical CPU<br>{{(n.details||n.last_details)?.cpu?.cores??'—'}} cores / {{(n.details||n.last_details)?.cpu?.sockets??'—'}} sockets</td>
          <td>RAM: {{usage((n.details||n.last_details)?.memory_bytes)}}<br>Swap: {{usage((n.details||n.last_details)?.swap_bytes)}}<br>Root: {{usage((n.details||n.last_details)?.rootfs_bytes)}}</td>
          <td>{{(n.details||n.last_details)?.pve_version||'—'}}<br>{{(n.details||n.last_details)?.uptime_seconds??'—'}} s</td></tr>
      </tbody></table></div>
    </template>
    <h3>История сборов</h3><div class="table-wrap"><table class="table"><thead><tr><th>Время</th><th>Операция / состояние</th><th>Результат</th></tr></thead><tbody><tr v-for="r in runs" :key="r.id"><td>{{r.created_at}}</td><td>{{r.operation}} · {{r.status}} {{r.error_code||''}}</td><td><button class="btn" :disabled="loading||r.status!=='succeeded'" @click="history(r.id)">Снимок</button></td></tr></tbody></table></div>
    <button class="btn" :disabled="loading||offset===0" @click="refresh(offset-20)">Назад</button> <button class="btn" :disabled="loading||runs.length<20||offset>=10000" @click="refresh(offset+20)">Далее</button>
  </section>
</template>
