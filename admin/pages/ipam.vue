<script setup lang="ts">
import { apiError } from '~/composables/useApi'
const api = useApi()
const auth = useAuth()
type Workspace = {id:string;name:string;role:'owner'|'manager'|'viewer'}
type Network = {id:string;workspace_id:string;name:string;cidr:string;vlan_id:number|null;description:string;etag:string;version:number}
type Interface = {id:string;workspace_id:string;asset_id:string;name:string;mac_address:string|null;etag:string}
type Address = {id:string;workspace_id:string;network_id:string;address:string;interface_id:string|null;hostname:string|null;description:string;status:'reserved'|'assigned';etag:string}
type Asset = {id:string;name:string;deleted_at:string|null}
type Page<T> = {data:T[];pagination:{total:number}}
const spaces = ref<Workspace[]>([])
const workspaceId = ref('')
const nets = ref<Network[]>([])
const ports = ref<Interface[]>([])
const ips = ref<Address[]>([])
const assets = ref<Asset[]>([])
const busy = ref(false)
const error = ref('')
const notice = ref('')
const selectedNet = ref<Network|null>(null)
const selectedPort = ref<Interface|null>(null)
const networkForm = reactive({name:'',cidr:'',vlan_id:'',description:''})
const portForm = reactive({asset_id:'',name:'',mac_address:''})
const ipForm = reactive({network_id:'',address:'',hostname:'',description:''})
const assignTargets = reactive<Record<string,string>>({})
const current = computed(()=>spaces.value.find(w=>w.id===workspaceId.value))
const canRead = computed(()=>auth.can('inventory.network.read'))
const canWrite = computed(()=>auth.can('inventory.network.manage') && ['owner','manager'].includes(current.value?.role||''))
const query = computed(()=>`?workspace_id=${encodeURIComponent(workspaceId.value)}`)
function report(e:unknown) {
  const failure=apiError(e)
  error.value=failure.status===412?'Данные изменены другим пользователем. Обновите страницу.':failure.status===409?'Конфликт: адрес занят, подсети пересекаются или объект ещё используется.':failure.message
}
async function initialize() {
  if (!canRead.value)return
  busy.value=true;error.value=''
  try {
    spaces.value=(await api.get<{data:Workspace[]}>('/workspaces')).data
    if (!spaces.value.some(w=>w.id===workspaceId.value))workspaceId.value=spaces.value[0]?.id||''
    await refresh()
  } catch(e){report(e)} finally{busy.value=false}
}
async function refresh() {
  if (!workspaceId.value) {nets.value=[];ports.value=[];ips.value=[];assets.value=[];return}
  busy.value=true;error.value=''
  try {
    const [networks,interfaces,addresses,inventory] = await Promise.all([
      api.get<Page<Network>>(`/ipam/networks${query.value}&limit=100`),
      api.get<Page<Interface>>(`/ipam/interfaces${query.value}&limit=100`),
      api.get<Page<Address>>(`/ipam/addresses${query.value}&limit=100`),
      auth.can('inventory.assets.read')?api.get<Page<Asset>>(`/inventory/assets${query.value}&limit=100`):Promise.resolve({data:[] as Asset[]}),
    ])
    nets.value=networks.data;ports.value=interfaces.data;ips.value=addresses.data
    assets.value=inventory.data.filter(a=>!a.deleted_at)
  } catch(e){report(e)} finally{busy.value=false}
}
function resetNet() {selectedNet.value=null;Object.assign(networkForm,{name:'',cidr:'',vlan_id:'',description:''})}
function editNet(net:Network) {selectedNet.value=net;Object.assign(networkForm,{name:net.name,cidr:net.cidr,vlan_id:net.vlan_id?.toString()||'',description:net.description})}
function resetPort() {selectedPort.value=null;Object.assign(portForm,{asset_id:'',name:'',mac_address:''})}
function editPort(port:Interface) {selectedPort.value=port;Object.assign(portForm,{asset_id:port.asset_id,name:port.name,mac_address:port.mac_address||''})}
async function saveNetwork() {
  if (!canWrite.value)return
  busy.value=true;error.value='';notice.value=''
  try {
    const payload={name:networkForm.name.trim(),vlan_id:networkForm.vlan_id===''?null:Number(networkForm.vlan_id),description:networkForm.description}
    if(selectedNet.value) await api.write('PATCH',`/ipam/networks/${selectedNet.value.id}${query.value}`,payload,true,{'If-Match':selectedNet.value.etag})
    else await api.write('POST',`/ipam/networks${query.value}`,{...payload,cidr:networkForm.cidr.trim()})
    resetNet();await refresh();notice.value='Подсеть сохранена.'
  }catch(e){report(e)}finally{busy.value=false}
}
async function savePort() {
  if(!canWrite.value)return
  busy.value=true;error.value='';notice.value=''
  try {
    const patch={name:portForm.name.trim(),mac_address:portForm.mac_address.trim()||null}
    if(selectedPort.value)await api.write('PATCH',`/ipam/interfaces/${selectedPort.value.id}${query.value}`,patch,true,{'If-Match':selectedPort.value.etag})
    else await api.write('POST',`/ipam/interfaces${query.value}`,{...patch,asset_id:portForm.asset_id})
    resetPort();await refresh();notice.value='Интерфейс сохранён.'
  }catch(e){report(e)}finally{busy.value=false}
}
async function reserveIp() {
  if(!canWrite.value)return
  busy.value=true;error.value='';notice.value=''
  try {
    await api.write('POST',`/ipam/addresses${query.value}`,{network_id:ipForm.network_id,address:ipForm.address.trim(),hostname:ipForm.hostname.trim()||null,description:ipForm.description})
    Object.assign(ipForm,{network_id:ipForm.network_id,address:'',hostname:'',description:''})
    await refresh();notice.value='IP-адрес зарезервирован.'
  }catch(e){report(e)}finally{busy.value=false}
}
async function remove(kind:'networks'|'interfaces'|'addresses', item:Network|Interface|Address) {
  if(!canWrite.value||!confirm('Удалить запись IPAM? Удаление запрещено, пока запись используется другими ресурсами.'))return
  busy.value=true;error.value='';notice.value=''
  try {
    await api.write('DELETE',`/ipam/${kind}/${item.id}${query.value}`,undefined,true,{'If-Match':item.etag})
    await refresh();notice.value='Запись удалена.'
  }catch(e){report(e)}finally{busy.value=false}
}
async function assignment(ip:Address, unassign=false) {
  if(!canWrite.value)return
  busy.value=true;error.value='';notice.value=''
  try {
    await api.write('POST',`/ipam/addresses/${ip.id}/${unassign?'unassign':'assign'}${query.value}`,unassign?undefined:{interface_id:assignTargets[ip.id]},true,{'If-Match':ip.etag})
    await refresh();notice.value=unassign?'Привязка снята.':'Адрес привязан к интерфейсу.'
  }catch(e){report(e)}finally{busy.value=false}
}
function switchWorkspace(){resetNet();resetPort();refresh()}
onMounted(initialize)
</script>

<template>
  <div>
    <h1>Сети и IPAM</h1>
    <p class="page-subtitle">Ручной учёт подсетей IPv4/IPv6, сетевых интерфейсов и адресов. Адреса назначаются только интерфейсам внутри текущего рабочего пространства.</p>
    <div v-if="error" class="alert" role="alert">{{ error }}</div>
    <div v-if="notice" class="alert success" role="status">{{ notice }}</div>
    <div v-if="!canRead" class="alert">Нет разрешения inventory.network.read.</div>
    <template v-else>
      <section class="panel">
        <div class="field"><label for="ipam-workspace">Рабочее пространство</label><select id="ipam-workspace" v-model="workspaceId" :disabled="busy" @change="switchWorkspace"><option v-for="w in spaces" :key="w.id" :value="w.id">{{ w.name }} ({{ w.role }})</option></select></div>
        <button class="btn" type="button" :disabled="busy||!workspaceId" @click="refresh">Обновить данные</button>
      </section>
      <section class="panel">
        <h2>Подсети ({{ nets.length }})</h2>
        <form v-if="canWrite" @submit.prevent="saveNetwork">
          <div class="form-row">
            <div class="field"><label>Название</label><input v-model="networkForm.name" maxlength="120" required placeholder="Office LAN" /></div>
            <div class="field"><label>CIDR</label><input v-model="networkForm.cidr" :disabled="!!selectedNet" required placeholder="192.0.2.0/24" /></div>
            <div class="field"><label>VLAN (необязательно)</label><input v-model="networkForm.vlan_id" type="number" min="1" max="4094" /></div>
            <div class="field"><label>Описание</label><input v-model="networkForm.description" maxlength="1024" /></div>
          </div>
          <div class="inline-actions"><button class="btn primary" :disabled="busy" type="submit">{{selectedNet?'Сохранить подсеть':'Добавить подсеть'}}</button><button v-if="selectedNet" class="btn" type="button" @click="resetNet">Отмена</button></div>
        </form>
        <table><thead><tr><th>Название</th><th>CIDR</th><th>VLAN</th><th>Действия</th></tr></thead><tbody><tr v-for="net in nets" :key="net.id"><td>{{net.name}}</td><td class="mono">{{net.cidr}}</td><td>{{net.vlan_id??'—'}}</td><td><button v-if="canWrite" class="btn" type="button" :disabled="busy" @click="editNet(net)">Изменить</button> <button v-if="canWrite" class="btn" type="button" :disabled="busy" @click="remove('networks',net)">Удалить</button></td></tr></tbody></table>
      </section>
      <section class="panel">
        <h2>Сетевые интерфейсы ({{ports.length}})</h2>
        <form v-if="canWrite" @submit.prevent="savePort">
          <div class="form-row">
            <div class="field"><label>Сервер / устройство</label><select v-model="portForm.asset_id" required :disabled="!!selectedPort"><option value="">Выберите объект</option><option v-for="asset in assets" :key="asset.id" :value="asset.id">{{asset.name}}</option></select></div>
            <div class="field"><label>Интерфейс</label><input v-model="portForm.name" maxlength="120" required placeholder="eth0" /></div>
            <div class="field"><label>MAC</label><input v-model="portForm.mac_address" placeholder="00:11:22:33:44:55" /></div>
          </div>
          <div class="inline-actions"><button class="btn primary" type="submit" :disabled="busy">{{selectedPort?'Сохранить интерфейс':'Добавить интерфейс'}}</button><button v-if="selectedPort" class="btn" type="button" @click="resetPort">Отмена</button></div>
        </form>
        <table><thead><tr><th>Устройство</th><th>Интерфейс</th><th>MAC</th><th>Действия</th></tr></thead><tbody><tr v-for="port in ports" :key="port.id"><td>{{assets.find(a=>a.id===port.asset_id)?.name||port.asset_id}}</td><td>{{port.name}}</td><td class="mono">{{port.mac_address||'—'}}</td><td><button v-if="canWrite" class="btn" type="button" :disabled="busy" @click="editPort(port)">Изменить</button> <button v-if="canWrite" class="btn" type="button" :disabled="busy" @click="remove('interfaces',port)">Удалить</button></td></tr></tbody></table>
      </section>
      <section class="panel">
        <h2>IP-адреса ({{ips.length}})</h2>
        <form v-if="canWrite" @submit.prevent="reserveIp">
          <div class="form-row">
            <div class="field"><label>Подсеть</label><select v-model="ipForm.network_id" required><option value="">Выберите подсеть</option><option v-for="net in nets" :key="net.id" :value="net.id">{{net.name}} — {{net.cidr}}</option></select></div>
            <div class="field"><label>IP</label><input v-model="ipForm.address" required placeholder="192.0.2.25" /></div>
            <div class="field"><label>Hostname</label><input v-model="ipForm.hostname" maxlength="253" placeholder="host.example.net" /></div>
            <div class="field"><label>Описание</label><input v-model="ipForm.description" maxlength="1024" /></div>
          </div>
          <button class="btn primary" type="submit" :disabled="busy">Зарезервировать адрес</button>
        </form>
        <table><thead><tr><th>Адрес</th><th>Сеть</th><th>Статус</th><th>Интерфейс / действия</th></tr></thead><tbody>
          <tr v-for="ip in ips" :key="ip.id"><td class="mono">{{ip.address}}</td><td>{{nets.find(n=>n.id===ip.network_id)?.name||ip.network_id}}</td><td>{{ip.status}}</td><td>
            <template v-if="canWrite"><template v-if="ip.interface_id"><span>{{ports.find(p=>p.id===ip.interface_id)?.name||ip.interface_id}}</span> <button class="btn" type="button" :disabled="busy" @click="assignment(ip,true)">Отвязать</button></template><template v-else><select v-model="assignTargets[ip.id]"><option value="">Выберите интерфейс</option><option v-for="port in ports" :key="port.id" :value="port.id">{{port.name}} ({{assets.find(a=>a.id===port.asset_id)?.name||port.asset_id}})</option></select> <button class="btn" type="button" :disabled="busy||!assignTargets[ip.id]" @click="assignment(ip)">Назначить</button></template> <button class="btn" type="button" :disabled="busy" @click="remove('addresses',ip)">Удалить</button></template>
            <span v-else>{{ports.find(p=>p.id===ip.interface_id)?.name||'—'}}</span>
          </td></tr>
        </tbody></table>
        <p class="page-subtitle">Показываются первые 100 записей каждого типа. Для полного реестра используйте пагинацию REST API.</p>
      </section>
    </template>
  </div>
</template>
