<script setup lang="ts">
import {apiError} from '~/composables/useApi'
type Volume={volume_id:string;content?:string;format?:string;size?:string;approximate_size?:string;parent_volume_id?:string;vmid?:string}
type Storage={storage:string;type:string;storage_key?:string;shared?:boolean;active?:boolean;enabled?:boolean;total?:string;used?:string;avail?:string;content?:string[];nodes?:string[];volume_state:string;volumes:Volume[]}
type Link={kind:string;iface:string;resolved:boolean}
type Interface={iface:string;type:string;active?:boolean;cidr?:string;cidr6?:string;address?:string;address6?:string;gateway?:string;gateway6?:string;vlan_id?:number;ovs_tag?:number;bridge_vids?:string;bridge_vlan_aware?:boolean;mtu?:number;bond_mode?:string;links:Link[]}
type Node={node:string;status:string;storage_state:string;network_state:string;storages:Storage[];interfaces:Interface[]}
type Guest={guest_id:string;node:string;state:string;disks:{device:string;volume_id?:string;storage?:string;source?:string;media?:string;storage_resolved:boolean;volume_resolved:boolean}[];nics:{device:string;bridge?:string;tag?:number;mac?:string;ip?:string;ip6?:string;bridge_resolved:boolean}[]}
type Snapshot={definitions_state:string;definitions:Storage[];details_complete:boolean;nodes:Node[];guests:Guest[]}
type Current={state:string;observed_at:string|null;snapshot:Snapshot|null}
type Run={id:string;status:string;created_at:string;error_code?:string;result?:{connection?:{storage_network_discovery?:Snapshot}}}
const props=defineProps<{targetId:string;profileConfigured:boolean}>(),api=useApi(),path=`/connectors/targets/${props.targetId}`
const current=ref<Current|null>(null),runs=ref<Run[]>([]),selected=ref<Run|null>(null),loading=ref(false),error=ref(''),node=ref(''),query=ref(''),offset=ref(0),volumePage=ref(0),guestPage=ref(0)
let disposed=false,seq=0,timer:ReturnType<typeof setInterval>|undefined
const snapshot=computed(()=>selected.value?selected.value.result?.connection?.storage_network_discovery??null:current.value?.snapshot??null)
const matches=(s:string)=>s.toLowerCase().includes(query.value.toLowerCase())
const nodes=computed(()=>(snapshot.value?.nodes??[]).filter(n=>!node.value||n.node===node.value))
const volumes=computed(()=>nodes.value.flatMap(n=>n.storages.flatMap(s=>s.volumes.map(v=>({node:n.node,storage:s.storage,...v})))).filter(v=>matches(`${v.node} ${v.volume_id} ${v.content??''} ${v.vmid??''}`)))
const guests=computed(()=>(snapshot.value?.guests??[]).filter(g=>(!node.value||g.node===node.value)&&matches(`${g.guest_id} ${g.node} ${g.disks.map(d=>d.volume_id).join(' ')} ${g.nics.map(n=>n.bridge).join(' ')}`)))
watch([query,node,snapshot],()=>{volumePage.value=0;guestPage.value=0})
const bytes=(v?:string)=>{if(v===undefined)return '—';const n=BigInt(v);const units=['B','KiB','MiB','GiB','TiB','PiB','EiB'];let d=1n,i=0;while(i<units.length-1&&n>=d*1024n){d*=1024n;i++}return `${n/d}${i?'.'+String(n%d*10n/d):''} ${units[i]}`}
async function read<T>(s:string){return (await api.get<{data:T}>(path+s)).data}
async function refresh(next=offset.value){if(loading.value||disposed)return;const n=++seq;loading.value=true;error.value='';try{const [state,history]=await Promise.all([read<Current>('/proxmox-storage-network'),read<Run[]>(`/runs?collector=proxmox.storage-network&limit=20&offset=${next}`)]);if(!disposed&&n===seq){current.value=state;runs.value=history;offset.value=next}}catch(e){if(!disposed&&n===seq){error.value=apiError(e).message;current.value=null;runs.value=[];selected.value=null}}finally{if(!disposed&&n===seq)loading.value=false}}
async function history(id:string){if(loading.value||disposed)return;const n=++seq;loading.value=true;error.value='';try{const row=await read<Run>('/runs/'+id);if(!disposed&&n===seq){selected.value=row;node.value=''}}catch(e){if(!disposed&&n===seq)error.value=apiError(e).message}finally{if(!disposed&&n===seq)loading.value=false}}
onMounted(()=>{void refresh();timer=setInterval(()=>{if(!document.hidden&&!selected.value)void refresh()},15000)});onBeforeUnmount(()=>{disposed=true;seq++;if(timer)clearInterval(timer)})
</script>
<template>
<section class="panel" data-testid="proxmox-storage-network-discovery">
  <h2>Proxmox: хранилища, volumes и сетевая топология</h2>
  <p v-if="!profileConfigured" role="alert">Требуется проверенный PVE TLS-профиль.</p>
  <p class="muted">Запустите <code>proxmox.storage-network</code> в «Планы и запуски». Данные отражают последний снимок с учётом ACL, а не текущий трафик или физическую коммутацию.</p>
  <button :disabled="loading" @click="selected=null;refresh()">Текущий снимок / обновить</button>
  <p v-if="error" role="alert">{{ error }}</p>
  <p v-if="selected">История: {{ selected.created_at }} — {{ selected.status }} {{ selected.error_code }}</p>
  <p v-else-if="current">{{ current.state }} · {{ current.observed_at || '—' }}</p>
  <template v-if="snapshot">
    <p>{{ snapshot.details_complete ? 'Все запрошенные разделы получены' : 'Частичный снимок' }} · Видимость ограничена правами PVE.</p>
    <label>Узел <select v-model="node"><option value="">Все</option><option v-for="n in snapshot.nodes" :key="n.node" :value="n.node">{{ n.node }}</option></select></label>
    <label>Поиск volumes / VMID / NIC <input v-model="query" type="search" /></label>
    <details><summary>Конфигурация storage — {{ snapshot.definitions_state }} ({{ snapshot.definitions.length }})</summary>
      <ul><li v-for="s in snapshot.definitions" :key="s.storage">{{ s.storage }} · {{ s.type }} · {{ s.shared ? 'shared' : 'local/не указано' }} · {{ s.content?.join(', ') }} · {{ s.nodes?.join(', ') || 'узлы не ограничены явно' }}</li></ul>
    </details>
    <article v-for="n in nodes" :key="n.node" class="panel">
      <h3>{{ n.node }} — {{ n.status }}</h3><p>Storage: {{ n.storage_state }} · Network: {{ n.network_state }}</p>
      <div class="scroll"><table><thead><tr><th>Storage</th><th>Тип / область</th><th>Всего / занято / свободно</th><th>Volumes</th></tr></thead><tbody>
        <tr v-for="s in n.storages" :key="s.storage"><td>{{ s.storage }}<small v-if="s.active===false"> · неактивно</small><small v-if="s.enabled===false"> · выключено</small></td><td>{{ s.type }} / {{ s.shared ? 'shared' : 'local/не указано' }}</td><td :title="`${s.total??'—'} / ${s.used??'—'} / ${s.avail??'—'} B`">{{ bytes(s.total) }} / {{ bytes(s.used) }} / {{ bytes(s.avail) }}</td><td>{{ s.volume_state }} · {{ s.volumes.length }}</td></tr>
      </tbody></table></div>
      <h4>Сетевые связи узла</h4><div class="scroll"><table><thead><tr><th>Интерфейс</th><th>Тип / состояние</th><th>Адрес / gateway</th><th>VLAN / MTU</th><th>Связанные интерфейсы</th></tr></thead><tbody>
        <tr v-for="i in n.interfaces" :key="i.iface"><td>{{ i.iface }}</td><td>{{ i.type }} · {{ i.active===true?'active':i.active===false?'inactive':'unknown' }} {{ i.bond_mode }}</td><td>{{ i.cidr||i.address||'—' }} {{ i.cidr6||i.address6 }} · {{ i.gateway||'—' }} {{ i.gateway6 }}</td><td>{{ i.vlan_id??i.ovs_tag??'—' }} {{ i.bridge_vlan_aware?'VLAN-aware':'' }} {{ i.bridge_vids }} / {{ i.mtu??'—' }}</td><td><div v-for="l in i.links" :key="l.kind+':'+l.iface">{{ i.iface }} → {{ l.iface }} ({{ l.kind }}{{ l.resolved?'':', не подтверждено' }})</div></td></tr>
      </tbody></table></div>
    </article>
    <h3>Volumes ({{ volumes.length }})</h3><div class="scroll"><table><thead><tr><th>Узел / Volume ID</th><th>Тип / формат</th><th>Размер</th><th>VMID</th></tr></thead><tbody><tr v-for="v in volumes.slice(volumePage*25,volumePage*25+25)" :key="v.node+':'+v.volume_id"><td>{{ v.node }} / {{ v.volume_id }}<small v-if="v.parent_volume_id"> ← {{ v.parent_volume_id }}</small></td><td>{{ v.content }} / {{ v.format }}</td><td :title="v.size">{{ v.size===undefined&&v.approximate_size!==undefined ? "≈ " : "" }}{{ bytes(v.size??v.approximate_size) }}</td><td>{{ v.vmid||'—' }}</td></tr></tbody></table></div>
    <button :disabled="volumePage===0" @click="volumePage--">Назад</button> <button :disabled="(volumePage+1)*25>=volumes.length" @click="volumePage++">Далее</button>
    <h3>Топология гостей ({{ guests.length }})</h3>
    <details v-for="g in guests.slice(guestPage*25,guestPage*25+25)" :key="g.guest_id"><summary>{{ g.guest_id }} → {{ g.node }} · {{ g.state }}</summary>
      <ul><li v-for="d in g.disks" :key="d.device">{{ d.device }} → {{ d.volume_id||d.media||d.source||'—' }} · {{ d.volume_resolved?'volume подтверждён':d.storage_resolved?'storage подтверждён, volume не виден':'нет подтверждённого storage' }}</li><li v-for="i in g.nics" :key="i.device">{{ i.device }} → {{ g.node }}/{{ i.bridge||'—' }} · {{ i.bridge_resolved?'bridge подтверждён':'bridge не подтверждён' }} · VLAN {{ i.tag??'—' }} · {{ i.mac }} {{ i.ip }} {{ i.ip6 }}</li></ul>
    </details>
    <button :disabled="guestPage===0" @click="guestPage--">Назад</button> <button :disabled="(guestPage+1)*25>=guests.length" @click="guestPage++">Далее</button>
  </template>
  <h3>История запусков</h3><button v-for="r in runs" :key="r.id" :disabled="loading" @click="history(r.id)">{{ r.created_at }} · {{ r.status }}</button><p><button :disabled="offset===0||loading" @click="refresh(Math.max(0,offset-20))">Назад</button> <button :disabled="runs.length<20||loading" @click="refresh(offset+20)">Далее</button></p>
</section>
</template>
<style scoped>
.scroll{overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:.5rem;border-bottom:1px solid var(--border,#444);overflow-wrap:anywhere}details{margin:.5rem 0}label{display:inline-block;margin:.5rem}button{margin:.2rem}
</style>
