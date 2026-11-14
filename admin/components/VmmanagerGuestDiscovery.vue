<script setup lang="ts">
import { apiError } from '~/composables/useApi'
type Disk={id:number;name:string|null;size_mib:string|null;storage_id:number|null;storage_name:string|null;type:string|null;bus:string|null;device:string|null}
type Nic={id:number;name:string|null;mac:string|null;model:string|null;bridge:string|null;ip_addresses:string[]|null}
type Guest={id:number;external_id:string;name:string;virtualization_type:'kvm'|'lxd';node_id:number|null;node_name:string|null;cluster_id:number|null;cluster_name:string|null;state:string;cpu_number:string|null;ram_mib:string|null;hdd_mib:string|null;os_name:string|null;os_id:number|null;account_id:number|null;ip_addresses:string[]|null;disks:Disk[]|null;interfaces:Nic[]|null;rescue_mode:boolean|null;ha_restore_on_fail:boolean|null}
type Snapshot={complete:boolean;visibility:string;counts:{nodes:number;guests:number;unlinked_guests:number};guests:Guest[]}
type Current={state:'not_collected'|'stale'|'available';observed_at:string|null;snapshot:Snapshot|null}
type Run={id:string;created_at:string;status:string;operation:string;error_code:string|null;result?:{connection?:{vmmanager_guest_discovery?:Snapshot}}|null}
const props=defineProps<{targetId:string;profileConfigured:boolean}>()
const api=useApi(),path=`/connectors/targets/${props.targetId}`
const current=ref<Current|null>(null),runs=ref<Run[]>([]),selected=ref<Run|null>(null),offset=ref(0),error=ref(''),loading=ref(false),filter=ref(''),clusterFilter=ref('')
let disposed=false,sequence=0,timer:ReturnType<typeof setInterval>|undefined
const snapshot=computed(()=>selected.value?selected.value.result?.connection?.vmmanager_guest_discovery||null:current.value?.snapshot||null)
const kind=ref(''),stateFilter=ref(''),guestPage=ref(0),expanded=ref<number|null>(null)
const filtered=computed(()=>{const q=filter.value.trim().toLowerCase();return (snapshot.value?.guests||[]).filter(g=>(!clusterFilter.value||String(g.cluster_id)===clusterFilter.value)&&(!kind.value||g.virtualization_type===kind.value)&&(!stateFilter.value||g.state===stateFilter.value)&&(!q||[g.name,String(g.id),g.node_name||'',g.os_name||'',...(g.ip_addresses||[])].some(v=>v.toLowerCase().includes(q))))})
const clusters=computed(()=>Array.from(new Map<string,string>((snapshot.value?.guests||[]).filter(g=>g.cluster_id!==null).map(g=>[String(g.cluster_id),g.cluster_name||`#${g.cluster_id}`]))))
const shown=computed(()=>filtered.value.slice(guestPage.value*50,guestPage.value*50+50))
watch([filter,clusterFilter,kind,stateFilter,selected],()=>{guestPage.value=0;expanded.value=null})
watch(snapshot,()=>{guestPage.value=Math.min(guestPage.value,Math.max(0,Math.ceil(filtered.value.length/50)-1));if(!filtered.value.some(g=>g.id===expanded.value))expanded.value=null})
async function read<T>(suffix:string){return (await api.get<{data:T}>(path+suffix)).data}
async function refresh(page=offset.value){if(loading.value||disposed)return;const seq=++sequence;loading.value=true;error.value='';try{const [state,history]=await Promise.all([read<Current>('/vmmanager-guests'),read<Run[]>(`/runs?collector=vmmanager.guests&limit=20&offset=${page}`)]);if(!disposed&&seq===sequence){current.value=state;runs.value=history;offset.value=page}}catch(e){if(!disposed&&seq===sequence){error.value=apiError(e).message;current.value=null;runs.value=[];selected.value=null}}finally{if(!disposed&&seq===sequence)loading.value=false}}
async function history(id:string){if(loading.value||disposed)return;const seq=++sequence;loading.value=true;error.value='';try{const row=await read<Run>('/runs/'+id);if(!disposed&&seq===sequence){selected.value=row;clusterFilter.value=''}}catch(e){if(!disposed&&seq===sequence){error.value=apiError(e).message;selected.value=null}}finally{if(!disposed&&seq===sequence)loading.value=false}}
onMounted(()=>{void refresh();timer=setInterval(()=>{if(!document.hidden&&!selected.value)void refresh()},15000)});onBeforeUnmount(()=>{disposed=true;sequence++;if(timer)clearInterval(timer)})
</script>
<template>
  <section class="panel" data-testid="vmmanager-guests-discovery">
    <h2>VMmanager: виртуальные машины и контейнеры</h2>
    <p v-if="!profileConfigured" role="alert">Для сбора настройте проверенный TLS-профиль VMmanager.</p>
    <p class="muted">План <code>vmmanager.guests</code>: Inspect сохраняет конфигурацию, Sync также обновляет Inventory. Размеры указаны в MiB; отсутствующие поля не равны нулю.</p>
    <div class="inline-actions"><button class="btn" :disabled="loading" @click="refresh()">Обновить</button><button v-if="selected" class="btn" :disabled="loading" @click="selected=null;clusterFilter=''">Текущий снимок</button></div>
    <p v-if="error" role="alert">{{error}}</p><p v-if="loading" role="status">Загрузка…</p>
    <p v-if="selected" class="muted">Исторический запуск {{selected.created_at}}. Не является текущим состоянием.</p>
    <p v-else-if="current?.state==='stale'" role="alert">Профиль или credentials изменились. Необходим новый сбор.</p>
    <p v-else-if="current?.state==='not_collected'">Снимок ещё не получен.</p>
    <template v-if="snapshot">
      <p v-if="!selected">Наблюдение: {{current?.observed_at}}</p>
      <p>Гости: {{snapshot.counts.guests}} · Узлы: {{snapshot.counts.nodes}} · Без видимого узла: {{snapshot.counts.unlinked_guests}}</p>
      <p v-if="!snapshot.complete" role="alert">Ограниченная видимость: снимок не является полным.</p>
      <div class="form-row"><label>Поиск<input v-model="filter" placeholder="Имя, ID, узел, ОС или IP"></label><label>Кластер<select v-model="clusterFilter"><option value="">Все</option><option v-for="[id,name] in clusters" :key="id" :value="id">{{name}}</option></select></label><label>Тип<select v-model="kind"><option value="">Все</option><option value="kvm">KVM</option><option value="lxd">LXD</option></select></label><label>Состояние<select v-model="stateFilter"><option value="">Все</option><option value="running">Running</option><option value="stopped">Stopped</option><option value="unknown">Unknown</option></select></label></div>
      <div class="table-wrap"><table class="table"><thead><tr><th>Гость</th><th>Узел / кластер</th><th>Состояние</th><th>CPU / RAM / диск</th><th>ОС / IP</th><th>Конфигурация</th></tr></thead><tbody><template v-for="g in shown" :key="g.id"><tr><td><strong>{{g.name}}</strong><br>#{{g.id}} · {{g.virtualization_type}}</td><td>{{g.node_name||'Не виден'}} (#{{g.node_id??'—'}})<br>{{g.cluster_name||g.cluster_id||'—'}}</td><td>{{g.state}}<br><span v-if="g.rescue_mode">Rescue</span></td><td>vCPU: {{g.cpu_number??'—'}}<br>RAM: {{g.ram_mib??'—'}}<br>HDD: {{g.hdd_mib??'—'}}</td><td>{{g.os_name??'—'}}<br>{{g.ip_addresses?.join(', ')||'—'}}</td><td><button class="btn" :aria-expanded="expanded===g.id" @click="expanded=expanded===g.id?null:g.id">{{expanded===g.id?'Свернуть':'Детали'}}</button></td></tr>
      <tr v-if="expanded===g.id"><td colspan="6"><p>Владелец ID: {{g.account_id??'—'}} · ОС ID: {{g.os_id??'—'}} · HA restore: {{g.ha_restore_on_fail??'—'}}</p><h4>Диски</h4><p v-if="g.disks===null">Данные не предоставлены API.</p><p v-else-if="!g.disks.length">Нет дисков.</p><ul v-else><li v-for="d in g.disks" :key="d.id">#{{d.id}} {{d.name||d.device||''}} · {{d.size_mib??'—'}} MiB · storage {{d.storage_name||d.storage_id||'—'}} · {{d.type||'—'}} / {{d.bus||'—'}}</li></ul><h4>Сетевые интерфейсы</h4><p v-if="g.interfaces===null">Данные не предоставлены API.</p><p v-else-if="!g.interfaces.length">Нет интерфейсов.</p><ul v-else><li v-for="n in g.interfaces" :key="n.id">#{{n.id}} {{n.name||''}} · {{n.mac||'—'}} · {{n.model||'—'}} · {{n.bridge||'—'}} · {{n.ip_addresses?.join(', ')||'—'}}</li></ul></td></tr></template><tr v-if="!shown.length"><td colspan="6">Нет подходящих гостей.</td></tr></tbody></table></div>
      <button class="btn" :disabled="guestPage===0" @click="guestPage--">Назад</button> <button class="btn" :disabled="(guestPage+1)*50>=filtered.length" @click="guestPage++">Далее</button> <span>Найдено: {{filtered.length}}</span>
    </template>
    <h3>История сборов</h3><div class="table-wrap"><table class="table"><thead><tr><th>Время</th><th>Операция / статус</th><th>Снимок</th></tr></thead><tbody><tr v-for="r in runs" :key="r.id"><td>{{r.created_at}}</td><td>{{r.operation}} · {{r.status}} {{r.error_code||''}}</td><td><button class="btn" :disabled="loading||r.status!=='succeeded'" @click="history(r.id)">Открыть</button></td></tr></tbody></table></div>
    <button class="btn" :disabled="loading||offset===0" @click="refresh(offset-20)">Назад</button> <button class="btn" :disabled="loading||runs.length<20||offset>=10000" @click="refresh(offset+20)">Далее</button>
  </section>
</template>
