<script setup lang="ts">
import { collectionUrl } from '~/composables/collectionNavigation'
import { apiError } from '~/composables/useApi'
import { inventoryContextUrl, selectWorkspace, queryUuid, inventoryAssetUrl } from '~/composables/inventoryNavigation'
import type { VaultVersion } from '~/composables/vaultProfiles'
const api=useApi(), auth=useAuth(),route=useRoute()
type Driver={provider:string;purpose:string;credentialTypes:string[];operations:string[];resourceTypes:string[];transport:string;actions:string[];write_actions_enabled:boolean;collectors?:import('~/composables/connectorLifecycle').CollectorDefinition[]}
const drivers=ref<Driver[]>([])
type Workspace={id:string;name:string;role:string}
type PveProfile={cluster:string|null;anchor_node:string;anchor_id:number|null;primary_pin:string;fallbacks:{host:string;port:number;pin:string}[]}
type PveConnection={version:string;cluster:string|null;anchor_node:string;anchor_id:number|null;endpoint_host:string;endpoint_port:number;failover:boolean;visible_members:number;quorate:boolean|null;permissions_visible?:boolean;root_privileges?:string[]}
const profileText=ref(''),pveConnection=ref<PveConnection|null>(null)
const canProfile=computed(()=>canManage.value&&workspaces.value.find(w=>w.id===workspace.value)?.role==='owner')
type Target={proxmox_profile?:PveProfile|null;id:string;workspace_id:string;provider:string;name:string;endpoint_host:string;endpoint_port:number;state:string;asset_id:string|null;version:number;etag:string;health_status:string;health_checked_at:string|null;health_success_at:string|null;health_duration_ms:number|null;health_error_code:string|null;circuit_open_until:string|null;consecutive_failures:number}
type Binding={id:string;connector_id:string;credential_id:string;purpose:string;type:string;state:string;credential_version:number|string}
type Credential={credential_id:string;name:string;type:string;state:string}
type Asset={id:string;name:string;type:string}
const workspaces=ref<Workspace[]>([]),workspace=ref(''),targets=ref<Target[]>([]),bindings=ref<Binding[]>([]),credentials=ref<Credential[]>([])
const selected=ref(''),busy=ref(false),message=ref(''),error=ref(''),loading=ref(false)
const selectedTarget=ref<Target|null>(null), targetOffset=ref(0), targetMore=ref(false), targetSearch=ref(''), providerFilter=ref(''), stateFilter=ref('')
const edit=reactive({name:'',endpoint_host:'',endpoint_port:8006}), editEtag=ref(''), assetSearch=ref('')
let loadSequence=0, openSequence=0, resourceSequence=0, initialized=false
const assets=ref<Asset[]>([]),assetSelection=ref(''),attachToAsset=ref(true)
const stepup=reactive({password:'',totp:''})
const pendingVersions=ref<VaultVersion[]>([])
type DiscoveryPlan={external_id:string;type:string;name:string|null;asset_id:string|null;action:string;conflict:string|null;candidate_ids:string[];parent_external_id:string|null}
type DiscoveryResource={external_id:string;resource_type:string;asset_id:string|null;presence:string;observed_name:string|null;conflict_code:string|null;missed_complete:number;last_generation:number;parent_external_id:string|null}
type DiscoveryRun={mode:string;discovered:number;types:Record<string,number>;outcomes?:Record<string,number>;preview?:{complete:boolean;summary:Record<string,number>;items:DiscoveryPlan[];truncated:boolean};snapshot?:{generation:number;complete:boolean;blocked:number;presence:Record<string,number>}}
const lastRun=ref<DiscoveryRun|null>(null)
const resources=ref<DiscoveryResource[]>([]),resourceOffset=ref(0),resourceHasMore=ref(false),resolutionAssets=ref<Record<string,string>>({})
const form=reactive({name:'',provider:'proxmox',endpoint_host:'',endpoint_port:8006,asset_id:''})
const binding=reactive({credential_id:'',purpose:'api'}), manualCredential=ref(false)
const providers=[{id:'pbs',port:8007,purpose:'api'},{id:'proxmox',port:8006,purpose:'api'},{id:'vmmanager',port:443,purpose:'api'},
  {id:'ssh',port:22,purpose:'ssh'},{id:'winrm',port:5986,purpose:'winrm'},
  {id:'snmp',port:161,purpose:'snmp'},{id:'prometheus',port:443,purpose:'api'},{id:'custom',port:443,purpose:'custom'}]
const canRead=computed(()=>auth.can('connectors.targets.read'))
const canManage=computed(()=>auth.can('connectors.targets.manage')&&['owner','manager'].includes(workspaces.value.find(w=>w.id===workspace.value)?.role||''))
const canBind=computed(()=>auth.can('connectors.credentials.manage')&&['owner','manager'].includes(workspaces.value.find(w=>w.id===workspace.value)?.role||''))
const canListBindings=computed(()=>auth.can('connectors.credentials.read'))
const activeDriver=computed(()=>drivers.value.find(d=>d.provider===active.value?.provider))
const canExecute=computed(()=>canBind.value&&auth.can('vault.credentials.use')&&!!activeDriver.value&&active.value?.state==='active')
const canRun=computed(()=>canExecute.value&&auth.can('inventory.assets.read')&&!!activeDriver.value?.operations.includes('inspect'))
const canHealth=computed(()=>canExecute.value&&!!activeDriver.value?.operations.includes('health'))
const canSync=computed(()=>canExecute.value&&!!activeDriver.value?.operations.includes('sync')&&auth.can('inventory.assets.create')&&auth.can('inventory.assets.update'))
const canValidate=computed(()=>canExecute.value&&!!activeDriver.value?.operations.includes('validate_rotation')&&auth.can('vault.credentials.read')&&auth.can('vault.credentials.update'))
const pendingRotation=computed(()=>pendingVersions.value.find(v=>v.state==='pending')||null)
const apiBinding=computed(()=>bindings.value.find(b=>b.purpose===activeDriver.value?.purpose&&b.state==='active'&&activeDriver.value?.credentialTypes.includes(b.type))||null)
const active=computed(()=>selectedTarget.value)
const compatibleCredentials=computed(()=>scopeCredentials.value.filter(c=>!activeDriver.value||activeDriver.value.credentialTypes.includes(c.type)))
const healthLabel=(status:string)=>({unknown:'Не проверен',healthy:'Доступен',degraded:'Сбой',unreachable:'Недоступен',auth_failed:'Ошибка авторизации',disabled:'Отключён'} as Record<string,string>)[status]||status
const circuitOpen=computed(()=>!!active.value?.circuit_open_until&&Date.parse(active.value.circuit_open_until)>Date.now())
const scopeCredentials=computed(()=>credentials.value.filter(c=>c.state==='active'))
function fail(e:unknown){const problem=apiError(e);error.value=problem.message+(problem.status===412?' — обновите конфигурацию перед повторной попыткой.':'')}
function reset(){error.value='';message.value=''}
function versionHeaders(target:Target){return {'If-Match':target.etag}}
function targetUrl(){return `/connectors/targets?${new URLSearchParams({workspace_id:workspace.value,limit:'50',offset:String(targetOffset.value),...(targetSearch.value.trim()?{search:targetSearch.value.trim()}:{}),...(providerFilter.value?{provider:providerFilter.value}:{}),...(stateFilter.value?{state:stateFilter.value}:{})})}`}
async function refreshHealth(){
  const ws=workspace.value,id=selected.value,seq=loadSequence;if(!ws||!canRead.value)return
  try{const data=(await api.get<{data:Target[]}>(targetUrl())).data
    if(workspace.value!==ws||seq!==loadSequence)return;targets.value=data;targetMore.value=data.length===50
    if(id){const fresh=(await api.get<{data:Target}>(`/connectors/targets/${id}`)).data;if(selected.value===id&&workspace.value===ws&&seq===loadSequence)selectedTarget.value=fresh}
  }catch(e){if(workspace.value===ws&&seq===loadSequence)fail(e)}
}
async function init(){if(!canRead.value)return
  try{drivers.value=(await api.get<{data:Driver[]}>('/connectors/drivers')).data;workspaces.value=(await api.get<{data:Workspace[]}>('/workspaces')).data;workspace.value=selectWorkspace(workspaces.value,route.query.workspace_id);await load();initialized=true
    const id=queryUuid(route.query.target_id);if(id){const target=(await api.get<{data:Target}>(`/connectors/targets/${id}`)).data;if(target.workspace_id===workspace.value)await open(target)}}
  catch(e){fail(e)}
}
async function refreshAfterLifecycle(){const id=selected.value;await refreshHealth();const target=active.value;if(target&&target.id===id){await loadResources(target,resourceOffset.value);if(canListBindings.value){try{const loaded=(await api.get<{data:Binding[]}>(`/connectors/targets/${id}/credential-bindings`)).data;if(selected.value===id)bindings.value=loaded}catch(e){if(selected.value===id)fail(e)}}}}
async function searchAssets(){const ws=workspace.value,seq=loadSequence;if(!ws||!auth.can('inventory.assets.read'))return
  try{const rows=(await api.get<{data:Asset[]}>(`/inventory/assets?${new URLSearchParams({workspace_id:ws,limit:'100',offset:'0',...(assetSearch.value.trim()?{search:assetSearch.value.trim()}:{})})}`)).data
    if(ws!==workspace.value||seq!==loadSequence)return;const keep=[form.asset_id,assetSelection.value].filter(Boolean);assets.value=[...rows,...assets.value.filter(a=>keep.includes(a.id)&&!rows.some(r=>r.id===a.id))]
  }catch(e){if(ws===workspace.value&&seq===loadSequence)fail(e)}
}
async function load(){const seq=++loadSequence,ws=workspace.value;openSequence++;resources.value=[];resourceOffset.value=0;resourceHasMore.value=false;resolutionAssets.value={};targets.value=[];bindings.value=[];credentials.value=[];assets.value=[];pendingVersions.value=[];lastRun.value=null;selected.value='';selectedTarget.value=null;stepup.password='';stepup.totp='';binding.credential_id='';if(!ws||!canRead.value)return
  reset();loading.value=true
  try{
    const rows=(await api.get<{data:Target[]}>(targetUrl())).data;if(seq!==loadSequence)return;targets.value=rows;targetMore.value=rows.length===50
    if(auth.can('inventory.credentials.read')){const rows=(await api.get<{data:Credential[]}>(`/inventory/credentials/scopes?workspace_id=${ws}`)).data;if(seq!==loadSequence)return;credentials.value=rows}
    await searchAssets()
  }catch(e){if(seq===loadSequence)fail(e)}finally{if(seq===loadSequence)loading.value=false}
}
async function open(target:Target){if(target.workspace_id!==workspace.value)return;const seq=++openSequence;resources.value=[];resourceOffset.value=0;resourceHasMore.value=false;resolutionAssets.value={};selected.value=target.id;selectedTarget.value=target;profileText.value=target.proxmox_profile?JSON.stringify(target.proxmox_profile,null,2):'';pveConnection.value=null;assetSelection.value=target.asset_id||'';Object.assign(edit,{name:target.name,endpoint_host:target.endpoint_host,endpoint_port:target.endpoint_port});editEtag.value=target.etag;bindings.value=[];binding.credential_id='';manualCredential.value=false;binding.purpose=drivers.value.find(d=>d.provider===target.provider)?.purpose||providers.find(p=>p.id===target.provider)?.purpose||'api';pendingVersions.value=[];lastRun.value=null;stepup.password='';stepup.totp='';reset();
  await navigateTo(`${inventoryContextUrl('connectors',workspace.value)}&target_id=${target.id}`,{replace:true});
  await loadResources(target,0);if(seq!==openSequence||selected.value!==target.id)return
  try{
    if(target.asset_id&&auth.can('inventory.assets.read')&&!assets.value.some(a=>a.id===target.asset_id)){const asset=(await api.get<{data:Asset}>(`/inventory/assets/${target.asset_id}`)).data;if(seq!==openSequence)return;assets.value.push(asset)}
    if(!canListBindings.value)return;const loaded=(await api.get<{data:Binding[]}>(`/connectors/targets/${target.id}/credential-bindings`)).data
    if(seq!==openSequence||selected.value!==target.id)return
    bindings.value=loaded
    const activeApi=loaded.find(b=>b.purpose===activeDriver.value?.purpose&&b.state==='active'&&activeDriver.value?.credentialTypes.includes(b.type))
    if(activeApi&&auth.can('vault.credentials.read')){
      const versions=(await api.get<{data:VaultVersion[]}>(`/vault/credentials/${activeApi.credential_id}/versions`)).data
      if(seq===openSequence&&selected.value===target.id)pendingVersions.value=versions
    }
  }catch(e){if(seq===openSequence)fail(e)}
}
watch(()=>form.provider,(provider)=>{const item=providers.find(p=>p.id===provider);if(item)form.endpoint_port=item.port})
watch(workspace,()=>{if(!initialized)return;targetOffset.value=0;targetSearch.value='';providerFilter.value='';stateFilter.value='';form.asset_id='';assetSearch.value='';void navigateTo(inventoryContextUrl('connectors',workspace.value),{replace:true});void load()})
async function filterTargets(){targetOffset.value=0;await load();await navigateTo(inventoryContextUrl('connectors',workspace.value),{replace:true})}
async function targetPage(delta:number){targetOffset.value=Math.max(0,Math.min(10000,targetOffset.value+delta));await load();await navigateTo(inventoryContextUrl('connectors',workspace.value),{replace:true})}
async function savePveProfile(){
  const item=active.value;if(!item||!canProfile.value||busy.value)return
  let profile:PveProfile;try{profile=JSON.parse(profileText.value)}catch{error.value='Некорректный JSON профиля.';return}
  if(!confirm('Подтвердить доверенные endpoints и SHA-256 сертификатов? Прежние grants будут недействительны.'))return
  busy.value=true;reset();try{const updated=(await api.write<{data:Target}>('PATCH',`/connectors/targets/${item.id}`,{proxmox_profile:profile},true,{'If-Match':item.etag})).data;await open(updated);message.value='Профиль сохранён. Выполните MFA health check.'}catch(e){fail(e)}finally{busy.value=false}
}
async function editTarget(){const item=active.value;if(!item||!canManage.value||busy.value)return;const patch:Record<string,string|number>={};if(edit.name!==item.name)patch.name=edit.name;if(edit.endpoint_host!==item.endpoint_host)patch.endpoint_host=edit.endpoint_host;if(edit.endpoint_port!==item.endpoint_port)patch.endpoint_port=edit.endpoint_port;if(!Object.keys(patch).length)return;if(!confirm('Изменение конфигурации делает прежние сервисные разрешения неактуальными. Продолжить?'))return;busy.value=true;reset();try{const updated=(await api.write<{data:Target}>('PATCH',`/connectors/targets/${item.id}`,patch,true,{'If-Match':editEtag.value})).data;await refreshHealth();await open(updated);message.value='Конфигурация обновлена.'}catch(e){fail(e)}finally{busy.value=false}}
async function create(){if(!canManage.value||!workspace.value)return;busy.value=true;reset()
  try{const result=await api.write<{data:Target}>('POST','/connectors/targets',{
    workspace_id:workspace.value,provider:form.provider,name:form.name,endpoint_host:form.endpoint_host,endpoint_port:form.endpoint_port,...(form.asset_id?{asset_id:form.asset_id}:{})})
    targetOffset.value=0;targetSearch.value='';providerFilter.value='';stateFilter.value='';await load();await open(result.data);message.value='Конфигурация сохранена.'
  }catch(e){fail(e)}finally{busy.value=false}
}
async function toggle(target:Target){if(!canManage.value)return;busy.value=true;reset()
  try{await api.write('PATCH',`/connectors/targets/${target.id}`,{state:target.state==='active'?'disabled':'active'},true,versionHeaders(target))
    await refreshHealth();const changed=(await api.get<{data:Target}>(`/connectors/targets/${target.id}`)).data;await open(changed)
    message.value='Состояние обновлено.'}
  catch(e){fail(e)}finally{busy.value=false}
}
async function remove(target:Target){if(!canManage.value||!confirm('Удалить конфигурацию? Сначала отсоедините credentials.'))return
  busy.value=true;reset();try{await api.write('DELETE',`/connectors/targets/${target.id}`,undefined,true,versionHeaders(target));await load();await navigateTo(inventoryContextUrl('connectors',workspace.value),{replace:true});message.value='Конфигурация удалена.'}
  catch(e){fail(e)}finally{busy.value=false}
}
async function attach(){if(!active.value||!canBind.value)return;busy.value=true;reset()
  try{const item=active.value
    await api.write('POST',`/connectors/targets/${item.id}/credential-bindings`,{credential_id:binding.credential_id,purpose:binding.purpose,attach_to_asset:attachToAsset.value&&!!item.asset_id&&auth.can('inventory.credentials.manage')},true,versionHeaders(item))
    await refreshHealth();const target=(await api.get<{data:Target}>(`/connectors/targets/${item.id}`)).data;await open(target);message.value='Credential привязан без чтения секрета.'}
  catch(e){fail(e)}finally{busy.value=false}
}
async function detach(b:Binding){if(!active.value||!canBind.value||!confirm('Удалить привязку?'))return;busy.value=true;reset()
  try{const item=active.value;await api.write('DELETE',`/connectors/targets/${item.id}/credential-bindings/${b.id}`,undefined,true,versionHeaders(item))
    await refreshHealth();const target=(await api.get<{data:Target}>(`/connectors/targets/${item.id}`)).data;await open(target);message.value='Привязка удалена.'}
  catch(e){fail(e)}finally{busy.value=false}
}
async function saveAsset(){if(!active.value||!canManage.value)return;busy.value=true;reset()
  try{const item=active.value
    await api.write('PATCH',`/connectors/targets/${item.id}`,{asset_id:assetSelection.value||null},true,versionHeaders(item))
    await refreshHealth();const updated=(await api.get<{data:Target}>(`/connectors/targets/${item.id}`)).data;await open(updated)
    message.value='Связь с Inventory обновлена.'
  }catch(e){fail(e)}finally{busy.value=false}
}
async function loadResources(target:Target,offset:number){
  const seq=++resourceSequence;
  if(!canRead.value||!auth.can('inventory.assets.read'))return
  try{const result=await api.get<{data:DiscoveryResource[]}>(`/connectors/targets/${target.id}/resources?limit=50&offset=${offset}`)
    if(selected.value!==target.id||seq!==resourceSequence)return
    resources.value=result.data;resourceOffset.value=offset;resourceHasMore.value=result.data.length===50
  }catch(e){if(selected.value===target.id)fail(e)}
}
async function resolveResource(resource:DiscoveryResource,mode:'link'|'create'){
  const item=active.value;if(!item||!canSync.value)return
  const asset=resolutionAssets.value[resource.external_id]?.trim()
  if(mode==='link'&&!asset)return
  if(!confirm(mode==='link'?'Связать внешний ресурс с указанным ручным объектом? Ручные поля сохранятся.':'Разрешить создание отдельного объекта при следующей синхронизации?'))return
  busy.value=true;reset()
  try{await api.write('POST',`/connectors/targets/${item.id}/resources/resolve`,{external_id:resource.external_id,generation:resource.last_generation,mode,...(mode==='link'?{asset_id:asset}:{})},true,versionHeaders(item))
    if(selected.value===item.id){await loadResources(item,resourceOffset.value);message.value='Решение сохранено. Выполните следующую синхронизацию.'}
  }catch(e){fail(e)}finally{busy.value=false}
}
async function executeRemote(sync:boolean){
  const item=active.value,credential=apiBinding.value
  if(busy.value||!item||!credential||!item.asset_id||(sync?!canSync.value:!canRun.value))return
  busy.value=true;reset();lastRun.value=null
  try{
    await api.write('POST',`/vault/credentials/${credential.credential_id}/authorize-use`,{
      asset_id:item.asset_id,purpose:credential.purpose,password:stepup.password,totp:stepup.totp})
    stepup.password='';stepup.totp=''
    const result=await api.write<{data:DiscoveryRun}>(
      'POST',`/connectors/targets/${item.id}/${sync?'sync':'inspect'}`,undefined,true,versionHeaders(item))
    if(selected.value!==item.id)return
    lastRun.value=result.data
    if(sync)await loadResources(item,0)
    message.value=sync?'Инвентаризация завершена.':'Удалённая проверка завершена.'
  }catch(e){fail(e)}finally{stepup.password='';stepup.totp='';await refreshHealth();busy.value=false}
}
async function checkHealth(){
  const item=active.value,credential=apiBinding.value
  if(!item||!credential||!item.asset_id||!canHealth.value)return
  busy.value=true;reset();lastRun.value=null
  try{
    await api.write('POST',`/vault/credentials/${credential.credential_id}/authorize-use`,{
      asset_id:item.asset_id,purpose:credential.purpose,password:stepup.password,totp:stepup.totp})
    const result=await api.write<{data:{status:string;duration_ms:number;connection?:PveConnection}}>('POST',`/connectors/targets/${item.id}/health`,undefined,true,versionHeaders(item))
    pveConnection.value=result.data.connection||null
    message.value=`Проверка доступности: ${result.data.status}, ${result.data.duration_ms} мс.`
  }catch(e){fail(e)}finally{stepup.password='';stepup.totp='';await refreshHealth();busy.value=false}
}
async function validatePendingRotation(){
  const item=active.value,credential=apiBinding.value,revision=pendingRotation.value?.secret_version
  if(!item||!credential||!item.asset_id||!revision||!canValidate.value)return
  busy.value=true;reset()
  try{
    await api.write('POST',`/vault/credentials/${credential.credential_id}/authorize-use`,{
      asset_id:item.asset_id,purpose:credential.purpose,password:stepup.password,totp:stepup.totp})
    const result=await api.write<{data:{validated:boolean;validated_targets:number;required_targets:number}}>(
      'POST',`/connectors/targets/${item.id}/validate-rotation`,{revision},true,versionHeaders(item))
    pendingVersions.value=(await api.get<{data:VaultVersion[]}>(`/vault/credentials/${credential.credential_id}/versions`)).data
    message.value=`Новая версия проверена через зарегистрированный драйвер. Подтверждено ${result.data.validated_targets} из ${result.data.required_targets} коннекторов.`
  }catch(e){fail(e)}finally{stepup.password='';stepup.totp='';busy.value=false}
}
onMounted(init)
</script>
<template>
  <div>
    <h1>Конфигурации подключений и Vault</h1>
    <p v-if="auth.can('vault.credentials.read')"><NuxtLink :to="inventoryContextUrl('vault',workspace)">Управление credentials в Vault</NuxtLink></p>
    <p class="page-subtitle">Исполняемые драйверы загружаются из Registry. Конфигурации без зарегистрированного драйвера не выполняют удалённых операций.</p>
    <div v-if="error" class="alert" role="alert">{{error}}</div>
    <div v-if="message" class="alert success" role="status">{{message}}</div>
    <section v-if="!canRead" class="panel">Недостаточно прав connectors.targets.read.</section>
    <template v-else>
      <section class="panel"><h2>Workspace</h2>
        <select aria-label="Workspace" v-model="workspace" :disabled="busy||loading"><option v-for="w in workspaces" :key="w.id" :value="w.id">{{w.name}} ({{w.role}})</option></select>
      </section>
      <section v-if="canManage" class="panel">
        <h2>Новая конфигурация</h2>
        <form @submit.prevent="create">
          <label>Название <input v-model="form.name" required maxlength="120" /></label>
          <label>Провайдер <select v-model="form.provider"><option v-for="p in providers" :key="p.id" :value="p.id">{{p.id}}{{drivers.some(d=>d.provider===p.id)?'':' (только конфигурация)'}}</option></select></label>
          <label>Адрес (DNS / IPv4 / IPv6, без URL и секретов) <input v-model="form.endpoint_host" required maxlength="253" /></label>
          <label>Порт <input v-model.number="form.endpoint_port" type="number" min="1" max="65535" required /></label>
          <div class="inline-actions"><label>Поиск Inventory asset <input v-model="assetSearch" maxlength="120" @keydown.enter.prevent="searchAssets" /></label><button class="btn" type="button" :disabled="busy||loading" @click="searchAssets">Найти asset</button></div>
          <label>Inventory asset (необязательно для сохранения, обязательно для исполнения)
            <select v-model="form.asset_id"><option value="">Без привязки</option><option v-for="a in assets" :key="a.id" :value="a.id">{{a.name}} — {{a.type}}</option></select>
          </label>
          <button class="btn" :disabled="busy||loading||!workspace">Создать конфигурацию</button>
        </form>
      </section>
      <section class="panel"><h2>Конфигурации</h2>
        <form class="form-row" @submit.prevent="filterTargets"><label>Поиск по имени или адресу<input v-model="targetSearch" maxlength="120" data-testid="connector-search" /></label><label>Провайдер<select v-model="providerFilter"><option value="">Все</option><option v-for="p in providers" :key="p.id" :value="p.id">{{p.id}}</option></select></label><label>Статус<select v-model="stateFilter"><option value="">Все</option><option value="active">active</option><option value="disabled">disabled</option></select></label><div><button class="btn" :disabled="busy||loading">Найти подключения</button></div></form>
        <button class="btn" type="button" :disabled="busy" @click="refreshHealth">Обновить состояние</button>
        <p v-if="targets.length===0">Нет конфигураций в выбранном пространстве.</p>
        <div v-else class="table-wrap"><table class="table"><thead><tr><th>Имя</th><th>Провайдер</th><th>Адрес</th><th>Статус</th><th>Доступность</th><th>Действия</th></tr></thead>
          <tbody><tr v-for="t in targets" :key="t.id"><td>{{t.name}}</td><td>{{t.provider}}</td><td>{{t.endpoint_host}}:{{t.endpoint_port}}</td><td>{{t.state}}</td><td>{{healthLabel(t.health_status)}}<small v-if="t.health_checked_at"> · {{t.health_checked_at}}</small></td>
            <td><button class="btn" type="button" :disabled="busy||loading" @click="open(t)">Привязки</button>
              <button v-if="canManage" class="btn" type="button" :disabled="busy" @click="toggle(t)">{{t.state==='active'?'Отключить':'Включить'}}</button>
              <button v-if="canManage" class="btn" type="button" :disabled="busy" @click="remove(t)">Удалить</button></td></tr></tbody></table></div>
        <div class="inline-actions"><button class="btn" type="button" :disabled="busy||loading||targetOffset===0" @click="targetPage(-50)">Назад</button><span>Страница {{targetOffset/50+1}}</span><button class="btn" type="button" :disabled="busy||loading||!targetMore||targetOffset>=10000" @click="targetPage(50)">Далее</button></div>
      </section>
      <section v-if="active?.provider==='proxmox'" class="panel">
        <h2>Профиль доверия Proxmox VE</h2>
        <p>{{active.proxmox_profile?'TLS pin + проверка идентичности кластера':'Совместимый режим: CA и hostname, без failover. Настройте профиль для усиленной защиты.'}}</p>
        <form v-if="canProfile" @submit.prevent="savePveProfile">
          <label>Профиль JSON (SHA-256 сертификатов без двоеточий)<textarea v-model="profileText" rows="10" placeholder='{"cluster":"production","anchor_node":"pve-01","anchor_id":1,"primary_pin":"…","fallbacks":[]}' required /></label>
          <button class="btn" :disabled="busy">Сохранить профиль доверия</button>
        </form>
        <p v-if="active.proxmox_profile">Кластер: {{active.proxmox_profile.cluster||'Standalone'}} · опорный узел: {{active.proxmox_profile.anchor_node}} · резервных endpoints: {{active.proxmox_profile.fallbacks.length}}</p>
        <dl v-if="pveConnection" class="kv-grid"><div><dt>PVE</dt><dd>{{pveConnection.version}}</dd></div><div><dt>Endpoint проверки</dt><dd>{{pveConnection.endpoint_host}}:{{pveConnection.endpoint_port}} {{pveConnection.failover?'(резервный)':'(основной)'}}</dd></div><div><dt>Quorum</dt><dd>{{pveConnection.quorate===null?'—':pveConnection.quorate?'Есть':'Нет'}}</dd></div><div><dt>Права на /</dt><dd>{{pveConnection.permissions_visible?(pveConnection.root_privileges||[]).join(', ')||'Нет видимых прав':'Не доступны'}}</dd></div></dl>
      </section>
      <section v-if="active&&canManage" class="panel"><h2>Настройки: {{active.name}}</h2><form @submit.prevent="editTarget"><div class="form-row"><label>Название подключения<input v-model="edit.name" required maxlength="120" data-testid="connector-edit-name" /></label><label>Адрес подключения<input v-model="edit.endpoint_host" required maxlength="253" /></label><label>Порт подключения<input v-model.number="edit.endpoint_port" type="number" min="1" max="65535" required /></label></div><button class="btn" :disabled="busy">Сохранить настройки</button><button class="btn" type="button" :disabled="busy" @click="open(active)">Загрузить текущие настройки</button></form></section>
      <section v-if="active" class="panel"><h2>Credentials: {{active.name}}</h2>
        <p>Выбрана конфигурация {{active.id}}. Версия {{active.version}}. Секреты не возвращаются из API.</p>
        <form v-if="canManage" @submit.prevent="saveAsset">
          <label>Целевой Inventory asset
            <select v-model="assetSelection"><option value="">Не выбран</option><option v-for="a in assets" :key="a.id" :value="a.id">{{a.name}} — {{a.type}}</option></select>
          </label><button class="btn" :disabled="busy">Сохранить связь с Inventory</button>
        </form>
        <ul><li v-for="b in bindings" :key="b.id">{{b.type}} / {{b.purpose}} — {{b.credential_id}} ({{b.state}})
          <button v-if="canBind" class="btn" type="button" :disabled="busy" @click="detach(b)">Отвязать</button></li></ul>
        <form v-if="canBind" @submit.prevent="attach">
          <label v-if="compatibleCredentials.length"><input type="checkbox" v-model="manualCredential" @change="binding.credential_id=''" /> Ввести UUID из Vault вручную</label>
          <label>Credential ID
            <select v-if="compatibleCredentials.length&&!manualCredential" v-model="binding.credential_id" required>
              <option value="">Выбрать credential</option><option v-for="c in compatibleCredentials" :key="c.credential_id" :value="c.credential_id">{{c.name}} — {{c.type}}</option>
            </select>
            <input v-else v-model="binding.credential_id" required placeholder="UUID credential" />
          </label>
          <label>Назначение <select v-model="binding.purpose" :disabled="!!activeDriver">
            <option value="api">api</option><option value="ssh">ssh</option><option value="winrm">winrm</option><option value="snmp">snmp</option><option value="custom">custom</option>
          </select></label>
          <label v-if="active.asset_id&&auth.can('inventory.credentials.manage')"><input v-model="attachToAsset" type="checkbox" /> Создать отдельную Inventory credential binding (не удаляется автоматически при отвязке коннектора)</label>
          <button class="btn" :disabled="busy||!binding.credential_id">Привязать credential</button>
        </form>
      </section>
      <NuxtLink v-if="active" class="btn" :to="collectionUrl(workspace,active.id,'plans')">Открыть центр сбора данных</NuxtLink>
      <ConnectorLifecycle v-if="active" :key="active.id" :target="active" :binding="apiBinding" :operations="activeDriver?.operations||[]" :collectors="activeDriver?.collectors||[]" :actions="activeDriver?.actions||[]" :write-actions="activeDriver?.write_actions_enabled===true" :manage="canBind" v-model:busy="busy" @changed="refreshAfterLifecycle" />
      <ConnectorIngestion v-if="active && canRead && auth.can('inventory.assets.read') && auth.can('ingestion.read')" :key="active.id+'-ingestion'" :target-id="active.id" :target="active" :binding="apiBinding" :can-manage="['owner','manager'].includes(workspaces.find(w=>w.id===workspace)?.role||'') && auth.can('ingestion.manage')" />
      <ConnectorDataExport v-if="active && canRead && auth.can('inventory.assets.read') && auth.can('inventory.transfer.read')" :key="active.id+'-exports'" :target-id="active.id" :can-ingestion="auth.can('ingestion.read')" />
      <ConnectorMetrics v-if="active && canRead && auth.can('inventory.assets.read')" :key="active.id+'-metrics'" :target-id="active.id" />
      <p v-if="active&&!activeDriver" class="muted">Для этого провайдера пока нет исполняемого драйвера.</p>
      <section v-if="active&&activeDriver" class="panel">
        <h2>{{ active.provider }} — подключение и Inventory</h2>
        <dl class="kv-grid"><div><dt>Доступность</dt><dd>{{healthLabel(active.health_status)}}</dd></div>
          <div><dt>Последняя проверка</dt><dd>{{active.health_checked_at||'—'}}</dd></div>
          <div><dt>Последний успех</dt><dd>{{active.health_success_at||'—'}}</dd></div>
          <div><dt>Длительность</dt><dd>{{active.health_duration_ms===null?'—':active.health_duration_ms+' мс'}}</dd></div>
          <div><dt>Ошибки подряд</dt><dd>{{active.consecutive_failures}}</dd></div>
          <div><dt>Код ошибки</dt><dd>{{active.health_error_code||'—'}}</dd></div>
          <div><dt>Circuit breaker</dt><dd>{{active.circuit_open_until?'Пауза до '+active.circuit_open_until:'Закрыт'}}</dd></div>
        </dl>
        <p v-if="circuitOpen" class="muted">Фоновые и обычные операции приостановлены. После исправления причины выполните ручную проверку доступности с MFA.</p>
        <p v-if="active.provider==='pbs'">PBS: HTTPS 8007, API token Vault типа api_token (user@realm!token:secret). Создайте план pbs.backups с операцией inspect.</p>
        <p v-if="active.provider==='prometheus'">Prometheus: HTTPS /metrics, Bearer API token. Создайте план prometheus.metrics с операцией inspect.</p>
        <p v-if="active.provider==='snmp'">SNMP: UDP, read-only; профиль SNMPv3 с SHA-256/512 и AES128 обязателен по умолчанию. SNMPv2c доступен только при явном разрешении на сервере. Собираются sysName, sysObjectID, интерфейсы и IP. Для автоматической проверки выдайте ограниченный service grant health с расписанием через API или CLI.</p>
        <p>Для запуска требуется активная связка credential с этой конфигурацией и выбранным Inventory asset, а также такое же назначение credential на самом asset. Доступ выдаётся на одно действие после проверки пароля и TOTP. TLS проверяется; для собственного CA задайте PROXMOX_CA_FILE или VMMANAGER_CA_FILE на сервере. VMmanager 6 использует API v3, порт 443 и токен из Vault; доступны узлы, KVM ВМ и LXD контейнеры. SSH использует доверенный SSH_KNOWN_HOSTS_FILE и пароль или приватный ключ; WinRM — HTTPS, credential auth=basic и WINRM_CA_FILE для частного CA. Linux/Windows собирают CPU, RAM, диски и интерфейсы только на чтение.</p>
        <form v-if="(canRun||canHealth||canSync||canValidate)&&apiBinding&&active.asset_id" @submit.prevent="executeRemote(false)">
          <label>Подтверждение пароля <input v-model="stepup.password" type="password" autocomplete="off" required /></label>
          <label>TOTP (6 цифр) <input v-model="stepup.totp" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required /></label>
          <button v-if="canRun" class="btn" type="submit" :disabled="busy||circuitOpen">Предпросмотр Discovery</button>
          <button v-if="canHealth" class="btn" type="button" :disabled="busy || !stepup.password || !/^[0-9]{6}$/.test(stepup.totp)" @click="checkHealth">Проверить доступность</button>
          <button v-if="canSync" class="btn" type="button" :disabled="busy || !stepup.password || !/^[0-9]{6}$/.test(stepup.totp)" @click="executeRemote(true)">Синхронизировать Inventory</button>
          <button v-if="canValidate && pendingRotation" class="btn primary" type="button" :disabled="busy || !stepup.password || !/^[0-9]{6}$/.test(stepup.totp)" data-testid="connector-validate-pending" @click="validatePendingRotation">
            Проверить новую версию №{{ pendingRotation.secret_version }}
          </button>
        </form>
        <p v-else>Для выполнения операции выберите asset, привяжите активный credential и проверьте права на использование Vault.</p>
        <p v-if="pendingRotation && canValidate" class="muted">Проверка ожидающего секрета использует одноразовое MFA-разрешение, не изменяет Inventory и сохраняет в Vault только подтверждение успешного подключения. После проверки активируйте версию в <NuxtLink :to="inventoryContextUrl('vault',workspace)">Vault</NuxtLink>.</p>
        <div v-if="lastRun" role="status">
          <p>Обнаружено объектов: {{lastRun.discovered}}. <span v-for="(count, type) in lastRun.types" :key="type">{{type}}: {{count}}. </span></p>
          <p v-if="lastRun.outcomes">Создано: {{lastRun.outcomes.created}}, обновлено: {{lastRun.outcomes.applied}}, без изменений: {{lastRun.outcomes.unchanged}}, конфликтов: {{lastRun.outcomes.conflicted}}.</p>
          <div v-if="lastRun.preview">
            <p>{{lastRun.preview.complete?'Полный видимый снимок':'Частичный снимок'}} — без записи Inventory. <span v-for="(count,action) in lastRun.preview.summary" :key="action">{{action}}: {{count}}. </span></p>
            <table><thead><tr><th>Ресурс</th><th>Действие</th><th>Причина / кандидаты</th></tr></thead><tbody>
              <tr v-for="entry in lastRun.preview.items" :key="entry.external_id"><td>{{entry.name||entry.external_id}} ({{entry.external_id}})</td><td>{{entry.action}}</td><td>{{entry.conflict||'—'}} <span v-if="entry.candidate_ids.length">{{entry.candidate_ids.join(', ')}}</span></td></tr>
            </tbody></table>
            <p v-if="lastRun.preview.truncated" class="muted">Показаны первые 100 ресурсов; счётчики включают весь снимок.</p>
          </div>
          <p v-if="lastRun.snapshot">Снимок №{{lastRun.snapshot.generation}}, {{lastRun.snapshot.complete?'полный':'частичный'}}; заблокировано: {{lastRun.snapshot.blocked}}. <span v-for="(count,state) in lastRun.snapshot.presence" :key="state">{{state}}: {{count}}. </span></p>
        </div>
      </section>
      <section v-if="active&&auth.can('inventory.assets.read')" class="panel">
        <h2>Обнаруженные ресурсы</h2>
        <p>Состояние внешнего ресурса не меняет статус и не удаляет объект Inventory. Для конфликтов имён выберите ручной объект или подтвердите отдельное создание.</p>
        <button class="btn" type="button" :disabled="busy" @click="loadResources(active,resourceOffset)">Обновить список</button>
        <p v-if="!resources.length" class="muted">Нет записей на этой странице.</p>
        <table v-else><thead><tr><th>Внешний ID / имя</th><th>Inventory UUID / родитель</th><th>Состояние</th><th>Разрешение</th></tr></thead><tbody>
          <tr v-for="resource in resources" :key="resource.external_id">
            <td>{{resource.external_id}}<br>{{resource.observed_name}}</td><td><NuxtLink v-if="resource.asset_id" :to="inventoryAssetUrl(resource.asset_id,workspace)">{{resource.asset_id}}</NuxtLink><span v-else>—</span><br>{{resource.parent_external_id||'—'}}</td>
            <td>{{resource.presence}} ({{resource.missed_complete}}) {{resource.conflict_code||''}}</td>
            <td><template v-if="resource.conflict_code==='ambiguous_name'&&canSync"><label>UUID ручного объекта <input v-model="resolutionAssets[resource.external_id]" type="text" /></label><button class="btn" type="button" :disabled="busy||!resolutionAssets[resource.external_id]" @click="resolveResource(resource,'link')">Связать</button> <button class="btn" type="button" :disabled="busy" @click="resolveResource(resource,'create')">Создать отдельно</button></template><span v-else>—</span></td>
          </tr>
        </tbody></table>
        <button class="btn" type="button" :disabled="busy||resourceOffset===0" @click="loadResources(active,Math.max(0,resourceOffset-50))">Назад</button>
        <button class="btn" type="button" :disabled="busy||!resourceHasMore||resourceOffset>=10000" @click="loadResources(active,resourceOffset+50)">Далее</button>
      </section>
    </template>
  </div>
</template>
