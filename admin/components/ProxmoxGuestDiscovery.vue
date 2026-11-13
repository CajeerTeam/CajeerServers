<script setup lang="ts">
import {apiError} from '~/composables/useApi'
type Disk={device:string;storage?:string;volume_id?:string;size?:string;media?:string;source?:string}
type Nic={device:string;model?:string;mac?:string;bridge?:string;tag?:number;ip?:string;ip6?:string}
type Config={cores?:number;sockets?:number;vcpus?:number;memory?:number;swap?:number;balloon?:number;cpu_model?:string;ostype?:string;bios?:string;machine?:string;arch?:string;unprivileged?:boolean;boot_order?:string[];tags?:string[];disks:Disk[];nics:Nic[]}
type Guest={external_id:string;vmid:number;type:'qemu'|'lxc';name:string;node:string;status:string;pool?:string;template?:boolean;config_state:string;runtime_state:string;config:Config|null;runtime:Record<string,string|number>|null;config_observed_at?:string;last_config?:Config|null;last_config_observed_at?:string|null}
type Snapshot={guest_count:number;details_complete:boolean;guests:Guest[]}
type Current={state:'not_collected'|'stale'|'available';observed_at:string|null;snapshot:Snapshot|null}
type Run={id:string;status:string;created_at:string;error_code:string|null;result?:{connection?:{guest_discovery?:Snapshot}}}
const props=defineProps<{targetId:string;profileConfigured:boolean}>()
const api=useApi(),path=`/connectors/targets/${props.targetId}`
const current=ref<Current|null>(null),runs=ref<Run[]>([]),selected=ref<Run|null>(null),offset=ref(0),error=ref(''),loading=ref(false),filter=ref(''),kind=ref(''),page=ref(0),expanded=ref('')
let disposed=false,seq=0,timer:ReturnType<typeof setInterval>|undefined
const snapshot=computed(()=>selected.value?selected.value.result?.connection?.guest_discovery||null:current.value?.snapshot||null)
const filtered=computed(()=>(snapshot.value?.guests||[]).filter(g=>(!kind.value||g.type===kind.value)&&`${g.vmid} ${g.name} ${g.node} ${g.pool||''}`.toLowerCase().includes(filter.value.toLowerCase())))
const visible=computed(()=>filtered.value.slice(page.value*25,page.value*25+25))
watch([filter,kind],()=>{page.value=0;expanded.value=''})
watch(snapshot,()=>{if(page.value*25>=filtered.value.length)page.value=0})
const detail=(g:Guest)=>g.config||g.last_config||null
async function read<T>(s:string){return (await api.get<{data:T}>(path+s)).data}
async function refresh(next=offset.value){if(loading.value||disposed)return;const id=++seq;loading.value=true;error.value='';try{const [state,history]=await Promise.all([read<Current>('/proxmox-guests'),read<Run[]>(`/runs?collector=proxmox.guests&limit=20&offset=${next}`)]);if(!disposed&&id===seq){current.value=state;runs.value=history;offset.value=next}}catch(e){if(!disposed&&id===seq){error.value=apiError(e).message;current.value=null;runs.value=[];selected.value=null}}finally{if(!disposed&&id===seq)loading.value=false}}
async function history(id:string){if(loading.value||disposed)return;const n=++seq;loading.value=true;error.value='';try{const row=await read<Run>('/runs/'+id);if(!disposed&&n===seq){selected.value=row;page.value=0;expanded.value=''}}catch(e){if(!disposed&&n===seq)error.value=apiError(e).message}finally{if(!disposed&&n===seq)loading.value=false}}
onMounted(()=>{void refresh();timer=setInterval(()=>{if(!document.hidden&&!selected.value)void refresh()},15000)});onBeforeUnmount(()=>{disposed=true;seq++;if(timer)clearInterval(timer)})
</script>
<template>
  <section class="panel" data-testid="proxmox-guest-discovery">
    <h2>Proxmox: VM и LXC</h2>
    <p v-if="!profileConfigured" role="alert">Для сбора требуется проверенный PVE TLS-профиль.</p>
    <p class="muted">Выберите <code>proxmox.guests</code> в разделе «Планы и запуски». Inspect сохраняет снимок, Sync также обновляет Inventory. Отсутствие в списке не считается удалением.</p>
    <div class="inline-actions"><button class="btn" :disabled="loading" @click="refresh()">Обновить</button><button v-if="selected" class="btn" @click="selected=null;page=0;expanded=''">Текущий снимок</button></div>
    <p v-if="error" role="alert">{{error}}</p><p v-if="loading" role="status">Загрузка…</p>
    <p v-if="selected">Исторический запуск {{selected.created_at}} · {{selected.status}}</p>
    <p v-else-if="current?.state==='stale'" role="alert">Профиль или credential изменились. Требуется новый сбор.</p>
    <p v-else-if="current?.state==='not_collected'">Снимок ещё не получен.</p>
    <template v-if="snapshot">
      <p>Гостей: {{snapshot.guest_count}} · {{snapshot.details_complete?'Все детали получены':'Детали частичны'}}. <span v-if="!selected">Последний успешный сбор: {{current?.observed_at}}</span></p>
      <div class="inline-actions"><label>Поиск <input v-model="filter" placeholder="VMID, имя, узел, pool"></label><label>Тип <select v-model="kind"><option value="">Все</option><option value="qemu">QEMU</option><option value="lxc">LXC</option></select></label></div>
      <div class="table-wrap"><table class="table"><thead><tr><th>VMID / имя</th><th>Узел / pool</th><th>Состояние</th><th>CPU / RAM</th><th>Детали</th></tr></thead><tbody>
        <template v-for="g in visible" :key="g.external_id"><tr><td>{{g.vmid}} · {{g.type.toUpperCase()}}<br>{{g.name}} <span v-if="g.template">(template)</span></td><td>{{g.node}}<br>{{g.pool||'—'}}</td><td>{{g.status}}<br>{{g.config_state}} / {{g.runtime_state}}</td><td>{{detail(g)?.cores??'—'}} cores / {{detail(g)?.memory??'—'}} MiB</td><td><button class="btn" @click="expanded=expanded===g.external_id?'':g.external_id">{{expanded===g.external_id?'Скрыть':'Открыть'}}</button></td></tr>
        <tr v-if="expanded===g.external_id"><td colspan="5"><p v-if="!g.config&&g.last_config" role="alert">Последние известные настройки: {{g.last_config_observed_at}} (не свежие)</p><p v-if="!detail(g)">Конфигурация недоступна.</p>
          <template v-if="detail(g)"><p>CPU: {{detail(g)?.cpu_model||'—'}} · sockets: {{detail(g)?.sockets??'—'}} · vCPU: {{detail(g)?.vcpus??'—'}} · RAM: {{detail(g)?.memory??'—'}} MiB · Swap: {{detail(g)?.swap??'—'}} MiB</p><p>OS: {{detail(g)?.ostype||'—'}} · BIOS: {{detail(g)?.bios||'—'}} · Machine: {{detail(g)?.machine||'—'}} · Unprivileged: {{detail(g)?.unprivileged??'—'}}</p><p>Boot: {{detail(g)?.boot_order?.join(', ')||'—'}} · Tags: {{detail(g)?.tags?.join(', ')||'—'}}</p>
          <h4>Диски и mount points</h4><table class="table"><thead><tr><th>Device</th><th>Storage / Volume</th><th>Размер</th></tr></thead><tbody><tr v-for="d in detail(g)?.disks||[]" :key="d.device"><td>{{d.device}}</td><td>{{d.volume_id||d.source||d.media||'—'}}</td><td>{{d.size||'—'}}</td></tr></tbody></table>
          <h4>Сетевые интерфейсы</h4><table class="table"><thead><tr><th>NIC</th><th>MAC / model</th><th>Bridge / VLAN</th><th>IP</th></tr></thead><tbody><tr v-for="n in detail(g)?.nics||[]" :key="n.device"><td>{{n.device}}</td><td>{{n.mac||'—'}} / {{n.model||'—'}}</td><td>{{n.bridge||'—'}} / {{n.tag??'—'}}</td><td>{{n.ip||'—'}} {{n.ip6||''}}</td></tr></tbody></table></template>
          <h4>Runtime</h4><dl v-if="g.runtime"><template v-for="(v,k) in g.runtime" :key="k"><dt>{{k}}</dt><dd>{{v}}</dd></template></dl><p v-else>Нет свежих runtime-данных.</p></td></tr></template>
      </tbody></table></div>
      <button class="btn" :disabled="page===0" @click="page--;expanded=''">Назад</button> <button class="btn" :disabled="(page+1)*25>=filtered.length" @click="page++;expanded=''">Далее</button> <span>{{filtered.length}} результатов</span>
    </template>
    <h3>История сборов</h3><div class="table-wrap"><table class="table"><thead><tr><th>Время</th><th>Состояние</th><th>Снимок</th></tr></thead><tbody><tr v-for="r in runs" :key="r.id"><td>{{r.created_at}}</td><td>{{r.status}} {{r.error_code||''}}</td><td><button class="btn" :disabled="loading||r.status!=='succeeded'" @click="history(r.id)">Открыть</button></td></tr></tbody></table></div>
    <button class="btn" :disabled="loading||offset===0" @click="refresh(offset-20)">Назад</button> <button class="btn" :disabled="loading||runs.length<20||offset>=10000" @click="refresh(offset+20)">Далее</button>
  </section>
</template>
