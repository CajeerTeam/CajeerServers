<script setup lang="ts">
import {onBeforeRouteLeave,onBeforeRouteUpdate} from 'vue-router'
import {apiError} from '~/composables/useApi'
import {queryUuid,selectWorkspace} from '~/composables/inventoryNavigation'
import {collectionUrl} from '~/composables/collectionNavigation'
import {proxmoxLabels,proxmoxViews,proxmoxView,proxmoxUrl,guestIdentity,scopedProxmox,type ProxmoxView} from '~/composables/proxmoxNavigation'
import type {LifecycleTarget,LifecycleBinding,CollectorDefinition} from '~/composables/connectorLifecycle'
const api=useApi(),auth=useAuth(),route=useRoute()
type Workspace={id:string;name:string;role:string}
type Target=LifecycleTarget&{provider:string;endpoint_host:string;endpoint_port:number;health_status:string;health_checked_at:string|null;proxmox_profile?:unknown}
type Binding=LifecycleBinding&{state:string;type:string}
type Driver={provider:string;purpose:string;credentialTypes:string[];operations:string[];actions:string[];write_actions_enabled:boolean;collectors?:CollectorDefinition[]}
const spaces=ref<Workspace[]>([]),drivers=ref<Driver[]>([]),targets=ref<Target[]>([]),selected=ref<Target|null>(null),binding=ref<Binding|null>(null)
const workspace=ref(''),provider=ref<'proxmox'|'pbs'>('proxmox'),search=ref(''),offset=ref(0),more=ref(false),loading=ref(false),busy=ref(false),error=ref(''),bindingError=ref(''),initialized=ref(false)
const allowed=computed(()=>auth.can('workspaces.read')&&auth.can('connectors.targets.read')&&auth.can('inventory.assets.read'))
const active=computed(()=>scopedProxmox(selected.value,workspace.value))
const driver=computed(()=>drivers.value.find(d=>d.provider===active.value?.provider))
const manage=computed(()=>auth.can('connectors.credentials.manage')&&['owner','manager'].includes(spaces.value.find(w=>w.id===workspace.value)?.role||''))
const views=computed(()=>proxmoxViews(active.value?.provider||'',allowed.value,auth.can('connectors.actions.read')))
const view=computed(()=>proxmoxView(route.query.view,views.value))
const guest=computed(()=>view.value==='actions'?guestIdentity(route.query.guest):'')
const invalidGuest=computed(()=>view.value==='actions'&&route.query.guest!=null&&!guest.value)
const canManageGuests=computed(()=>views.value.includes('actions')&&manage.value&&auth.can('connectors.actions.execute')&&auth.can('vault.credentials.use')&&!!binding.value&&!!active.value?.asset_id&&active.value.state==='active'&&!!active.value.proxmox_profile)
const connectorLink=computed(()=>`/connectors?${new URLSearchParams({workspace_id:workspace.value,...(active.value?{target_id:active.value.id}:{})})}`)
let generation=0,disposed=false,browsePage=0
async function read<T>(path:string){return (await api.get<{data:T}>(path)).data}
const valid=(n:number)=>!disposed&&n===generation
function reset(){selected.value=null;binding.value=null;bindingError.value='';busy.value=false}
async function loadBinding(t:Target,n:number){
  if(!auth.can('connectors.credentials.read')){binding.value=null;return}
  try{const rows=await read<Binding[]>(`/connectors/targets/${t.id}/credential-bindings`);if(valid(n)&&selected.value?.id===t.id){const d=drivers.value.find(x=>x.provider===t.provider);binding.value=rows.find(b=>b.state==='active'&&!!d&&b.purpose===d.purpose&&d.credentialTypes.includes(b.type))||null}}
  catch(e){if(valid(n)){binding.value=null;bindingError.value=apiError(e).message}}
}
async function load(requested='',page=0){
  const n=++generation,ws=workspace.value;reset();targets.value=[];offset.value=page;more.value=false;error.value='';loading.value=true
  try{
    if(!allowed.value||!ws)return
    // Explicit deep links must resolve to the selected workspace, never silently switch scopes.
    let t:Target|null=null
    if(requested){const fresh=await read<Target>(`/connectors/targets/${requested}`);if(!valid(n))return;t=scopedProxmox(fresh,ws);if(!t){error.value='Подключение не относится к выбранному Workspace или не является PVE/PBS.';return}provider.value=t.provider as 'proxmox'|'pbs'}
    const q=new URLSearchParams({workspace_id:ws,provider:provider.value,limit:'50',offset:String(page),...(search.value.trim()?{search:search.value.trim()}:{})})
    const rows=await read<Target[]>('/connectors/targets?'+q);if(!valid(n))return
    targets.value=rows.filter(r=>scopedProxmox(r,ws)&&r.provider===provider.value);more.value=rows.length===50
    if(t){selected.value=t;await loadBinding(t,n)}
  }catch(e){if(valid(n)){reset();error.value=apiError(e).message}}finally{if(valid(n))loading.value=false}
}
async function init(){
  if(!allowed.value)return;loading.value=true
  try{const [w,d]=await Promise.all([read<Workspace[]>('/workspaces'),read<Driver[]>('/connectors/drivers')]);if(disposed)return;spaces.value=w;drivers.value=d;initialized.value=true;await syncRoute()}
  catch(e){if(!disposed)error.value=apiError(e).message}finally{if(!disposed)loading.value=false}
}
async function syncRoute(){
  if(!initialized.value||disposed)return
  const requested=queryUuid(route.query.workspace_id),ws=selectWorkspace(spaces.value,route.query.workspace_id),id=queryUuid(route.query.target_id)
  if(route.query.workspace_id!=null&&(!requested||requested!==ws)){++generation;reset();targets.value=[];workspace.value='';error.value='Workspace недоступен.';loading.value=false;return}
  if(route.query.target_id!=null&&!id){++generation;reset();targets.value=[];workspace.value=ws;error.value='Некорректный идентификатор подключения.';loading.value=false;return}
  if(ws===workspace.value&&id&&id===active.value?.id)return
  if(ws!==workspace.value){search.value='';provider.value='proxmox'}
  workspace.value=ws;const page=browsePage;browsePage=0;await load(id,id?0:page)
}
async function changeWorkspace(){if(busy.value)return;const ws=workspace.value;generation++;reset();targets.value=[];search.value='';provider.value='proxmox';browsePage=0;await navigateTo(proxmoxUrl(ws))}
async function choose(t:Target){if(busy.value||!scopedProxmox(t,workspace.value))return;await navigateTo(proxmoxUrl(workspace.value,t.id,t.provider==='pbs'?'backups':'overview'))}
async function browse(page=0){if(busy.value)return;reset();if(route.query.target_id){browsePage=page;await navigateTo(proxmoxUrl(workspace.value))}else await load('',page)}
async function go(v:ProxmoxView,id=''){if(!active.value||busy.value||!views.value.includes(v))return;await navigateTo(proxmoxUrl(workspace.value,active.value.id,v,id))}
async function refreshTarget(){const t=active.value,n=generation;if(!t)return;try{const fresh=await read<Target>(`/connectors/targets/${t.id}`);if(!valid(n)||active.value?.id!==t.id)return;if(!scopedProxmox(fresh,workspace.value)||fresh.provider!==t.provider){reset();error.value='Контекст подключения изменился.';return}if(fresh.version!==t.version)binding.value=null;selected.value=fresh;await loadBinding(fresh,n)}catch(e){if(valid(n)){reset();error.value=apiError(e).message}}}
watch(()=>[route.query.workspace_id,route.query.target_id],()=>{void syncRoute()})
watch(allowed,ok=>{if(!ok){generation++;reset();targets.value=[]}else if(!initialized.value)void init()})
onBeforeRouteLeave(()=>!busy.value)
onBeforeRouteUpdate(()=>!busy.value)
onMounted(()=>void init())
onBeforeUnmount(()=>{disposed=true;generation++})
</script>
<template>
  <div data-testid="proxmox-center">
    <h1>Proxmox — управление инфраструктурой</h1>
    <p class="page-subtitle">PVE и PBS в одном рабочем пространстве. Данные инвентаризации получены сборщиками, изменения выполняются только через Safe Action Dispatcher.</p>
    <p v-if="!allowed" role="alert">Недостаточно прав для доступа к Proxmox.</p>
    <template v-else>
      <section class="panel pve-scope" aria-label="Область управления">
        <div class="form-row"><label>Workspace<select v-model="workspace" data-testid="pve-workspace" :disabled="busy||loading" @change="changeWorkspace"><option value="" disabled>Выберите Workspace</option><option v-for="w in spaces" :key="w.id" :value="w.id">{{w.name}}</option></select></label>
          <label>Система<select v-model="provider" data-testid="pve-provider" :disabled="busy||loading" @change="browse()"><option value="proxmox">Proxmox VE</option><option value="pbs">Proxmox Backup Server</option></select></label></div>
        <form class="toolbar" @submit.prevent="browse()"><label class="pve-search">Поиск подключения<input v-model="search" maxlength="200" :disabled="busy||loading" data-testid="pve-search" /></label><button class="btn" :disabled="busy||loading||!workspace">Найти</button></form>
        <p v-if="loading" role="status">Загрузка подключений…</p><UiAlert :message="error" />
        <div v-if="targets.length" class="pve-targets"><button v-for="t in targets" :key="t.id" type="button" class="btn pve-target" :aria-pressed="active?.id===t.id" :disabled="busy||loading" :data-testid="`pve-target-${t.id}`" @click="choose(t)"><strong>{{t.name}}</strong><span>{{t.endpoint_host}}:{{t.endpoint_port}}</span><small>{{t.state}} · {{t.health_status}}</small></button></div>
        <p v-else-if="!loading&&!error" class="muted">Нет подключений этого типа. <NuxtLink :to="connectorLink">Настроить подключение</NuxtLink>.</p>
        <div class="inline-actions section-gap"><button class="btn" :disabled="busy||loading||offset===0" @click="browse(offset-50)">Назад</button><span class="muted">Страница {{offset/50+1}}</span><button class="btn" :disabled="busy||loading||!more||offset>=10000" @click="browse(offset+50)">Далее</button><NuxtLink v-if="!busy" class="btn" :to="connectorLink">Настройки и Vault</NuxtLink></div>
      </section>
      <template v-if="active">
        <section class="panel" data-testid="pve-active-target"><div class="toolbar"><div><h2>{{active.name}}</h2><p class="muted mono">{{active.provider==='pbs'?'PBS':'PVE'}} · {{active.endpoint_host}}:{{active.endpoint_port}} · {{active.state}} · {{active.health_status}}</p></div><button class="btn" :disabled="busy||loading" @click="refreshTarget">Обновить подключение</button></div>
          <p v-if="active.health_checked_at" class="muted small">Проверка доступности: {{active.health_checked_at}}</p><p v-if="active.provider==='proxmox'&&!active.proxmox_profile" class="alert info">Настройте доверенный TLS-профиль PVE для новых сборов и удалённых операций.</p>
          <p v-if="bindingError" role="alert">Привязка credential: {{bindingError}}</p>
          <nav class="inline-actions" aria-label="Разделы Proxmox"><button v-for="v in views" :key="v" type="button" class="btn" :aria-pressed="view===v" :disabled="busy" :data-testid="`pve-view-${v}`" @click="go(v)">{{proxmoxLabels[v]}}</button></nav>
        </section>
        <div v-if="!loading" :key="`${active.id}:${view}:${guest}`">
          <ProxmoxClusterDiscovery v-if="view==='overview'" :target-id="active.id" :profile-configured="!!active.proxmox_profile" />
          <ProxmoxGuestDiscovery v-else-if="view==='guests'" :target-id="active.id" :profile-configured="!!active.proxmox_profile" :manage-guests="canManageGuests" @manage-guest="go('actions',$event)" />
          <ProxmoxStorageNetworkDiscovery v-else-if="view==='storage'" :target-id="active.id" :profile-configured="!!active.proxmox_profile" />
          <ProxmoxTaskHistory v-else-if="view==='tasks'" :target-id="active.id" :profile-configured="!!active.proxmox_profile" />
          <PbsBackupDiscovery v-else-if="view==='backups'" :target-id="active.id" />
          <p v-else-if="invalidGuest" role="alert">Некорректный идентификатор гостя. Выберите ресурс из списка VM/LXC. <button class="btn" @click="go('guests')">К списку гостей</button></p>
          <template v-else-if="view==='actions'"><p v-if="guest" class="muted" data-testid="pve-guest-context">Выбранный гость: {{guest}}. Перед операцией проверяется актуальное соответствие Inventory. <button class="btn" :disabled="busy" @click="go('actions')">Все VM / LXC</button></p><ConnectorLifecycle :target="active" :binding="binding" :operations="driver?.operations||[]" :actions="driver?.actions||[]" :write-actions="driver?.write_actions_enabled===true" :manage="manage" initial-tab="actions" :initial-resource="guest" :only-actions="true" v-model:busy="busy" @changed="refreshTarget" /></template>
          <template v-else-if="view==='plans'"><ConnectorLifecycle :target="active" :binding="binding" :operations="driver?.operations||[]" :collectors="(driver?.collectors||[]).filter(c=>active?.provider!=='proxmox'||!!active.proxmox_profile||!['proxmox.cluster','proxmox.guests','proxmox.storage-network','proxmox.tasks'].includes(c.name))" :actions="[]" :write-actions="false" :manage="manage" initial-tab="plans" v-model:busy="busy" @changed="refreshTarget" /><NuxtLink v-if="!busy" :to="collectionUrl(workspace,active.id,'metrics')">Метрики и временные ряды</NuxtLink></template>
        </div>
      </template>
      <p v-else-if="!loading&&!error" class="empty">Выберите подключение для просмотра инфраструктуры.</p>
    </template>
  </div>
</template>
<style scoped>
.pve-targets{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:10px}.pve-target{text-align:left;white-space:normal;overflow-wrap:anywhere;display:flex;flex-direction:column;gap:5px;padding:14px}.pve-target small{opacity:.85}.pve-search{flex:1;min-width:160px}.pve-scope .toolbar{align-items:end}
</style>
