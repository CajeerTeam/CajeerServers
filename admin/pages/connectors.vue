<script setup lang="ts">
import { apiError } from '~/composables/useApi'
import { inventoryContextUrl, selectWorkspace } from '~/composables/inventoryNavigation'
const api=useApi(), auth=useAuth(),route=useRoute()
type Workspace={id:string;name:string;role:string}
type Target={id:string;workspace_id:string;provider:string;name:string;endpoint_host:string;endpoint_port:number;state:string;asset_id:string|null;version:number;etag:string}
type Binding={id:string;connector_id:string;credential_id:string;purpose:string;type:string;state:string}
type Credential={credential_id:string;name:string;type:string;state:string}
const workspaces=ref<Workspace[]>([]),workspace=ref(''),targets=ref<Target[]>([]),bindings=ref<Binding[]>([]),credentials=ref<Credential[]>([])
const selected=ref(''),busy=ref(false),message=ref(''),error=ref('')
const form=reactive({name:'',provider:'proxmox',endpoint_host:'',endpoint_port:8006})
const binding=reactive({credential_id:'',purpose:'api'})
const providers=[{id:'proxmox',port:8006,purpose:'api'},{id:'vmmanager',port:443,purpose:'api'},
  {id:'ssh',port:22,purpose:'ssh'},{id:'winrm',port:5986,purpose:'winrm'},
  {id:'snmp',port:161,purpose:'snmp'},{id:'custom',port:443,purpose:'custom'}]
const canRead=computed(()=>auth.can('connectors.targets.read'))
const canManage=computed(()=>auth.can('connectors.targets.manage')&&['owner','manager'].includes(workspaces.value.find(w=>w.id===workspace.value)?.role||''))
const canBind=computed(()=>auth.can('connectors.credentials.manage')&&canManage.value)
const canListBindings=computed(()=>auth.can('connectors.credentials.read'))
const active=computed(()=>targets.value.find(t=>t.id===selected.value)||null)
const scopeCredentials=computed(()=>credentials.value.filter(c=>c.state==='active'))
function fail(e:unknown){error.value=apiError(e).message}
function reset(){error.value='';message.value=''}
function versionHeaders(target:Target){return {'If-Match':target.etag}}
async function init(){if(!canRead.value)return
  try{workspaces.value=(await api.get<{data:Workspace[]}>('/workspaces')).data;workspace.value=selectWorkspace(workspaces.value,route.query.workspace_id);await load()}
  catch(e){fail(e)}
}
async function load(){targets.value=[];bindings.value=[];credentials.value=[];selected.value='';if(!workspace.value||!canRead.value)return
  reset()
  try{
    targets.value=(await api.get<{data:Target[]}>(`/connectors/targets?workspace_id=${workspace.value}`)).data
    if(auth.can('inventory.credentials.read')) credentials.value=(await api.get<{data:Credential[]}>(`/inventory/credentials/scopes?workspace_id=${workspace.value}`)).data
  }catch(e){fail(e)}
}
async function open(target:Target){selected.value=target.id;bindings.value=[];reset();if(!canListBindings.value)return
  try{bindings.value=(await api.get<{data:Binding[]}>(`/connectors/targets/${target.id}/credential-bindings`)).data}
  catch(e){fail(e)}
}
watch(()=>form.provider,(provider)=>{const item=providers.find(p=>p.id===provider);if(item){form.endpoint_port=item.port;binding.purpose=item.purpose}})
watch(workspace,()=>{void navigateTo(inventoryContextUrl('connectors',workspace.value),{replace:true});void load()})
async function create(){if(!canManage.value||!workspace.value)return;busy.value=true;reset()
  try{const result=await api.write<{data:Target}>('POST','/connectors/targets',{
    workspace_id:workspace.value,provider:form.provider,name:form.name,endpoint_host:form.endpoint_host,endpoint_port:form.endpoint_port})
    await load();await open(result.data);message.value='Конфигурация сохранена. Подключение к удалённой системе не выполнялось.'
  }catch(e){fail(e)}finally{busy.value=false}
}
async function toggle(target:Target){if(!canManage.value)return;busy.value=true;reset()
  try{await api.write('PATCH',`/connectors/targets/${target.id}`,{state:target.state==='active'?'disabled':'active'},true,versionHeaders(target))
    const id=target.id;await load();const changed=targets.value.find(t=>t.id===id);if(changed)await open(changed)
    message.value='Состояние обновлено.'}
  catch(e){fail(e)}finally{busy.value=false}
}
async function remove(target:Target){if(!canManage.value||!confirm('Удалить конфигурацию? Сначала отсоедините credentials.'))return
  busy.value=true;reset();try{await api.write('DELETE',`/connectors/targets/${target.id}`,undefined,true,versionHeaders(target));await load();message.value='Конфигурация удалена.'}
  catch(e){fail(e)}finally{busy.value=false}
}
async function attach(){if(!active.value||!canBind.value)return;busy.value=true;reset()
  try{const item=active.value
    await api.write('POST',`/connectors/targets/${item.id}/credential-bindings`,{credential_id:binding.credential_id,purpose:binding.purpose},true,versionHeaders(item))
    const id=item.id;await load();const target=targets.value.find(t=>t.id===id);if(target)await open(target);message.value='Credential привязан без чтения секрета.'}
  catch(e){fail(e)}finally{busy.value=false}
}
async function detach(b:Binding){if(!active.value||!canBind.value||!confirm('Удалить привязку?'))return;busy.value=true;reset()
  try{const item=active.value;await api.write('DELETE',`/connectors/targets/${item.id}/credential-bindings/${b.id}`,undefined,true,versionHeaders(item))
    const id=item.id;await load();const target=targets.value.find(t=>t.id===id);if(target)await open(target);message.value='Привязка удалена.'}
  catch(e){fail(e)}finally{busy.value=false}
}
onMounted(init)
</script>
<template>
  <div>
    <h1>Конфигурации подключений и Vault</h1>
    <p class="page-subtitle">Учёт целей интеграций и привязок секретов к ним. Реальное подключение Proxmox, VMmanager, SSH, WinRM и SNMP в версии 0.3.6 не выполняется.</p>
    <div v-if="error" class="alert" role="alert">{{error}}</div>
    <div v-if="message" class="alert success" role="status">{{message}}</div>
    <section v-if="!canRead" class="panel">Недостаточно прав connectors.targets.read.</section>
    <template v-else>
      <section class="panel"><h2>Workspace</h2>
        <select aria-label="Workspace" v-model="workspace"><option v-for="w in workspaces" :key="w.id" :value="w.id">{{w.name}} ({{w.role}})</option></select>
      </section>
      <section v-if="canManage" class="panel">
        <h2>Новая конфигурация</h2>
        <form @submit.prevent="create">
          <label>Название <input v-model="form.name" required maxlength="120" /></label>
          <label>Провайдер <select v-model="form.provider"><option v-for="p in providers" :key="p.id" :value="p.id">{{p.id}}</option></select></label>
          <label>Адрес (DNS / IPv4 / IPv6, без URL и секретов) <input v-model="form.endpoint_host" required maxlength="253" /></label>
          <label>Порт <input v-model.number="form.endpoint_port" type="number" min="1" max="65535" required /></label>
          <button class="btn" :disabled="busy||!workspace">Создать конфигурацию</button>
        </form>
      </section>
      <section class="panel"><h2>Конфигурации</h2>
        <p v-if="targets.length===0">Нет конфигураций в выбранном пространстве.</p>
        <table v-else><thead><tr><th>Имя</th><th>Провайдер</th><th>Адрес</th><th>Статус</th><th>Действия</th></tr></thead>
          <tbody><tr v-for="t in targets" :key="t.id"><td>{{t.name}}</td><td>{{t.provider}}</td><td>{{t.endpoint_host}}:{{t.endpoint_port}}</td><td>{{t.state}}</td>
            <td><button class="btn" type="button" @click="open(t)">Привязки</button>
              <button v-if="canManage" class="btn" type="button" :disabled="busy" @click="toggle(t)">{{t.state==='active'?'Отключить':'Включить'}}</button>
              <button v-if="canManage" class="btn" type="button" :disabled="busy" @click="remove(t)">Удалить</button></td></tr></tbody></table>
      </section>
      <section v-if="active" class="panel"><h2>Credentials: {{active.name}}</h2>
        <p>Выбрана конфигурация {{active.id}}. Версия {{active.version}}. Plaintext и ciphertext не выводятся.</p>
        <ul><li v-for="b in bindings" :key="b.id">{{b.type}} / {{b.purpose}} — {{b.credential_id}} ({{b.state}})
          <button v-if="canBind" class="btn" type="button" :disabled="busy" @click="detach(b)">Отвязать</button></li></ul>
        <form v-if="canBind" @submit.prevent="attach">
          <label>Credential ID
            <select v-if="scopeCredentials.length" v-model="binding.credential_id" required>
              <option value="">Выбрать credential</option><option v-for="c in scopeCredentials" :key="c.credential_id" :value="c.credential_id">{{c.name}} — {{c.type}}</option>
            </select>
            <input v-else v-model="binding.credential_id" required placeholder="UUID credential" />
          </label>
          <label>Назначение <select v-model="binding.purpose">
            <option value="api">api</option><option value="ssh">ssh</option><option value="winrm">winrm</option><option value="snmp">snmp</option><option value="custom">custom</option>
          </select></label>
          <button class="btn" :disabled="busy||!binding.credential_id">Привязать credential</button>
        </form>
      </section>
    </template>
  </div>
</template>
