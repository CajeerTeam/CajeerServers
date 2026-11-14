<script setup lang="ts">
import {onBeforeRouteLeave,onBeforeRouteUpdate} from 'vue-router'
import {apiError} from '~/composables/useApi'
import {queryUuid,inventoryAssetUrl,inventoryContextUrl} from '~/composables/inventoryNavigation'
import {osProviders,osProvider,osProviderLabels,healthLabels,osViews,osView,osViewLabels,osNetworkUrl,scopedOsTarget,sumOsSummaries,isOsSummary,type OsSummary,type OsProvider,type OsTarget,type OsView} from '~/composables/osNetworkNavigation'
import type {LifecycleBinding,CollectorDefinition} from '~/composables/connectorLifecycle'
const api=useApi(),auth=useAuth(),route=useRoute()
type Workspace={id:string;name:string;role:string}
type Driver={provider:string;purpose:string;credentialTypes:string[];operations:string[];collectors?:CollectorDefinition[]}
type Binding=LifecycleBinding&{state:string;type:string}
type Summary={provider:OsProvider;counts:OsSummary|null;error:string}
const spaces=ref<Workspace[]>([]),drivers=ref<Driver[]>([]),workspace=ref(''),provider=ref<OsProvider>('ssh'),search=ref(''),offset=ref(0),targets=ref<OsTarget[]>([]),summaries=ref<Summary[]>([]),selected=ref<OsTarget|null>(null),binding=ref<Binding|null>(null)
const loading=ref(false),dashboardLoading=ref(false),busy=ref(false),more=ref(false),error=ref(''),dashboardError=ref(''),bindingError=ref(''),checked=ref(''),initialized=ref(false),refreshKey=ref(0)
const allowed=computed(()=>auth.can('workspaces.read')&&auth.can('connectors.targets.read'))
const active=computed(()=>allowed.value?scopedOsTarget(selected.value,workspace.value):null)
const views=computed(()=>osViews(active.value?.provider||null,auth.can)),view=computed(()=>osView(route.query.view,views.value))
const driver=computed(()=>drivers.value.find(d=>d.provider===active.value?.provider))
const manager=computed(()=>['owner','manager'].includes(spaces.value.find(w=>w.id===workspace.value)?.role||''))
const manage=computed(()=>manager.value&&auth.can('connectors.credentials.manage'))
const collectors=computed(()=> (driver.value?.collectors||[]).filter(c=>{
 if(active.value?.provider==='routeros'&&!active.value.os_network_profile)return false
 if(c.name==='routeros.configuration-backup')return !!active.value?.os_network_profile&&auth.can('network.configuration.backup')
 if(c.name.endsWith('.events'))return !!active.value?.os_network_profile&&auth.can('system.events.read')
 if(['ssh.inventory','winrm.inventory','snmp.inventory','ssh.metrics','winrm.metrics','snmp.metrics','routeros.metrics'].includes(c.name))return true
 return !!active.value?.os_network_profile
}))
const statLabels:Record<keyof OsSummary,string>={total:'Подключений',healthy:'Доступны',problem:'Со сбоями',unchecked:'Не проверены',disabled:'Отключены',without_profile:'Без профиля доверия',unlinked:'Без CMDB asset'}
const totals=computed(()=>sumOsSummaries(summaries.value.flatMap(s=>s.counts?[s.counts]:[])))
const statItems=computed(()=>(Object.keys(statLabels) as (keyof OsSummary)[]).map(key=>({key,label:statLabels[key],value:summaries.value.some(s=>s.counts)?totals.value[key]:'—'})))
const incomplete=computed(()=>summaries.value.length!==4||summaries.value.some(s=>!s.counts||s.error))
const connectorLink=computed(()=>inventoryContextUrl('connectors',workspace.value)+(active.value?'&target_id='+active.value.id:''))
let seq=0,dashSeq=0,disposed=false,browsePage=0
async function read<T>(path:string){return (await api.get<{data:T}>(path)).data}
const valid=(n:number)=>!disposed&&n===seq&&allowed.value
function clear(){busy.value=false;selected.value=null;binding.value=null;bindingError.value='';targets.value=[];more.value=false;offset.value=0}
async function list(ws:string,p:OsProvider,page:number,limit:number,q=''){return await read<OsTarget[]>('/connectors/targets?'+new URLSearchParams({workspace_id:ws,provider:p,limit:String(limit),offset:String(page),...(q?{search:q}:{})}))}
async function dashboard(){
 const n=++dashSeq,ws=workspace.value;dashboardLoading.value=true;summaries.value=[];checked.value='';dashboardError.value=''
 if(!allowed.value||!ws){dashboardLoading.value=false;return}
 const results=await Promise.all(osProviders.map(async(p):Promise<Summary>=>{
  try{const r=await api.get<{data:OsTarget[];summary:OsSummary}>('/connectors/targets?'+new URLSearchParams({workspace_id:ws,provider:p,limit:'1',include_summary:'1'}));if(!isOsSummary(r.summary))throw new Error('Некорректные данные Dashboard');return {provider:p,counts:r.summary,error:''}}
  catch(e){return {provider:p,counts:null,error:apiError(e).message}}
 }))
 if(disposed||n!==dashSeq||!allowed.value)return
 summaries.value=results;checked.value=new Date().toLocaleString();dashboardLoading.value=false
 if(results.some(r=>r.error))dashboardError.value='Не все провайдеры доступны. Итоги относятся только к успешно загруженным данным.'
}
async function loadBinding(t:OsTarget,n:number){
 if(!auth.can('connectors.credentials.read'))return
 try{const rows=await read<Binding[]>(`/connectors/targets/${t.id}/credential-bindings`);if(valid(n)&&selected.value?.id===t.id){const d=drivers.value.find(x=>x.provider===t.provider);binding.value=rows.find(b=>b.state==='active'&&!!d&&b.purpose===d.purpose&&d.credentialTypes.includes(b.type))||null}}
 catch(e){if(valid(n))bindingError.value=apiError(e).message}
}
async function load(id='',page=0){
 const n=++seq,ws=workspace.value;clear();offset.value=page;loading.value=true;error.value=''
 try{if(!ws||!allowed.value)return
  if(id){const t=await read<OsTarget>(`/connectors/targets/${id}`);if(!valid(n))return;const scoped=scopedOsTarget(t,ws);if(!scoped){error.value='Подключение не относится к выбранному Workspace или OS / Network.';return}provider.value=scoped.provider;selected.value=scoped;await loadBinding(scoped,n);if(!valid(n))return}
  const p=provider.value,rows=await list(ws,p,page,50,search.value.trim());if(!valid(n))return;targets.value=rows.filter(t=>scopedOsTarget(t,ws)&&t.provider===p);more.value=rows.length===50
 }catch(e){if(valid(n)){clear();error.value=apiError(e).message}}finally{if(valid(n))loading.value=false}
}
async function syncRoute(){
 if(!initialized.value||disposed||!allowed.value)return
 const raw=route.query.workspace_id,w=queryUuid(raw),id=queryUuid(route.query.target_id),ws=raw==null?spaces.value[0]?.id||'':w
 if(!ws||!spaces.value.some(s=>s.id===ws)||route.query.target_id!=null&&!id){++seq;++dashSeq;clear();workspace.value='';summaries.value=[];checked.value='';loading.value=false;dashboardLoading.value=false;error.value='Workspace или идентификатор подключения недоступен.';return}
 const changed=ws!==workspace.value,p=osProvider(route.query.provider)||'ssh'
 if(changed){workspace.value=ws;search.value='';void dashboard()}
 if(!changed&&id&&id===active.value?.id)return
 const page=browsePage;browsePage=0;provider.value=p;await load(id,id?0:page)
}
async function init(){if(!allowed.value)return;loading.value=true;error.value='';try{const [w,d]=await Promise.all([read<Workspace[]>('/workspaces'),read<Driver[]>('/connectors/drivers')]);if(disposed||!allowed.value)return;spaces.value=w;drivers.value=d;initialized.value=true;await syncRoute()}catch(e){if(!disposed)error.value=apiError(e).message}finally{if(!disposed)loading.value=false}}
async function changeWorkspace(ws:string){if(!busy.value)await navigateTo(osNetworkUrl(ws))}
async function browse(p:OsProvider=provider.value,page=0){if(busy.value)return;if(route.query.target_id||osProvider(route.query.provider)!==p){search.value=p===provider.value?search.value:'';browsePage=page;await navigateTo(osNetworkUrl(workspace.value,undefined,'overview',p))}else{provider.value=p;await load('',page)}}
async function choose(t:OsTarget){if(!busy.value&&scopedOsTarget(t,workspace.value))await navigateTo(osNetworkUrl(workspace.value,t.id,'overview',t.provider))}
async function go(v:OsView){if(!busy.value&&active.value&&views.value.includes(v))await navigateTo(osNetworkUrl(workspace.value,active.value.id,v,active.value.provider))}
async function refreshTarget(){const t=active.value;if(!t)return;const n=++seq;try{const fresh=await read<OsTarget>(`/connectors/targets/${t.id}`);if(!valid(n))return;if(!scopedOsTarget(fresh,workspace.value)||fresh.provider!==t.provider){clear();error.value='Контекст подключения изменился.';return}selected.value=fresh;binding.value=null;bindingError.value='';await loadBinding(fresh,n)}catch(e){if(valid(n)){clear();error.value=apiError(e).message}}}
async function refreshAll(){if(busy.value||loading.value||dashboardLoading.value)return;void dashboard();await load(active.value?.id||'',offset.value);refreshKey.value++}
watch(()=>[route.query.workspace_id,route.query.target_id,route.query.provider],()=>void syncRoute())
watch(allowed,ok=>{if(!ok){++seq;++dashSeq;clear();summaries.value=[];initialized.value=false;workspace.value='';loading.value=false;dashboardLoading.value=false}else if(!initialized.value)void init()})
onBeforeRouteLeave(()=>!busy.value);onBeforeRouteUpdate(()=>!busy.value)
onMounted(()=>void init());onBeforeUnmount(()=>{disposed=true;seq++;dashSeq++})
</script>
<template>
 <div data-testid="os-network-center">
  <h1>OS / Network — центр управления</h1><p class="page-subtitle">Linux, Windows, SNMP и RouterOS: доступность, Inventory, события, метрики и конфигурации в одном Workspace.</p>
  <UiAlert v-if="!allowed" message="Недостаточно прав для просмотра OS / Network." />
  <template v-else>
   <section class="panel"><div class="form-row"><label>Workspace<select data-testid="os-workspace" :value="workspace" :disabled="loading||busy" @change="changeWorkspace(($event.target as HTMLSelectElement).value)"><option value="" disabled>Выберите Workspace</option><option v-for="w in spaces" :key="w.id" :value="w.id">{{w.name}}</option></select></label><button class="btn" :disabled="busy||loading||dashboardLoading||!workspace" @click="refreshAll">Обновить всё</button><NuxtLink v-if="workspace&&!busy" class="btn" :to="connectorLink">Настройки подключений</NuxtLink></div><UiAlert :message="error" /></section>
   <section v-if="workspace" class="panel" data-testid="os-dashboard"><h2>Dashboard подключений</h2><p class="muted">Последние сохранённые результаты health check, не онлайн-проверка. {{checked?'Обновлено: '+checked:''}}</p><p v-if="dashboardLoading" role="status">Загрузка Dashboard…</p><UiAlert :message="dashboardError" /><template v-if="summaries.length"><p v-if="incomplete" role="status">Неполный охват: недоступные провайдеры исключены из итогов. Показанные числа — нижняя граница.</p><div class="os-stats"><div v-for="stat in statItems" :key="stat.key" class="os-stat" :data-testid="'os-stat-'+stat.key"><span>{{stat.label}}</span><strong>{{stat.value}}</strong></div></div><div class="os-providers"><button v-for="s in summaries" :key="s.provider" class="btn os-provider" :class="{primary:provider===s.provider}" :disabled="busy||loading" :data-testid="'os-provider-'+s.provider" @click="browse(s.provider)"><strong>{{osProviderLabels[s.provider]}}</strong><span v-if="s.error">Не удалось загрузить</span><span v-else>{{s.counts?.total??'—'}} · {{s.counts?.problem??'—'}} со сбоями</span></button></div></template></section>
   <section v-if="workspace" class="panel"><h2>Подключения — {{osProviderLabels[provider]}}</h2><form class="form-row" @submit.prevent="browse(provider)"><label>Провайдер<select :value="provider" data-testid="os-provider-select" :disabled="busy||loading" @change="browse(($event.target as HTMLSelectElement).value as OsProvider)"><option v-for="p in osProviders" :key="p" :value="p">{{osProviderLabels[p]}}</option></select></label><label>Поиск подключения<input v-model="search" maxlength="120" :disabled="busy||loading" /></label><button class="btn" :disabled="busy||loading">Найти</button></form><p v-if="loading" role="status">Загрузка подключений…</p><div class="table-wrap"><table class="table"><thead><tr><th>Подключение</th><th>Адрес</th><th>Доступность</th><th>Последняя проверка</th><th>Действие</th></tr></thead><tbody><tr v-for="t in targets" :key="t.id" :data-testid="'os-row-'+t.id"><td>{{t.name}}</td><td class="mono">{{t.endpoint_host}}:{{t.endpoint_port}}</td><td><span :class="['tag',t.health_status==='healthy'?'good':['degraded','unreachable','auth_failed'].includes(t.health_status)?'bad':'']">{{healthLabels[t.health_status]||t.health_status}}</span> · {{t.state}}</td><td>{{t.health_checked_at||'—'}}</td><td><button class="btn" :aria-pressed="active?.id===t.id" :disabled="busy||loading" :data-testid="'os-target-'+t.id" @click="choose(t)">Открыть</button></td></tr></tbody></table></div><p v-if="!targets.length&&!loading&&!error">Нет подключений. <NuxtLink :to="connectorLink">Добавить подключение</NuxtLink>.</p><div class="inline-actions"><button class="btn" :disabled="busy||loading||offset===0" @click="browse(provider,offset-50)">Назад</button><span>Страница {{offset/50+1}}</span><button class="btn" :disabled="busy||loading||!more||offset>=10000" @click="browse(provider,offset+50)">Далее</button></div></section>
   <template v-if="active&&!loading"><section class="panel" data-testid="os-active-target"><h2>{{active.name}}</h2><p class="muted">{{osProviderLabels[active.provider]}} · {{active.endpoint_host}}:{{active.endpoint_port}} · {{healthLabels[active.health_status]||active.health_status}}</p><UiAlert v-if="!active.os_network_profile" tone="info" message="Профиль доверия не настроен. Для защищённого подробного сбора настройте SSH/TLS/SNMP identity."/><UiAlert :message="bindingError"/><div class="inline-actions"><NuxtLink v-if="active.asset_id&&auth.can('inventory.assets.read')&&!busy" class="btn" :to="inventoryAssetUrl(active.asset_id,workspace)">Карточка CMDB</NuxtLink><button v-for="v in views" :key="v" class="btn" :class="{primary:view===v}" :aria-pressed="view===v" :data-testid="'os-view-'+v" :disabled="busy" @click="go(v)">{{osViewLabels[v]}}</button></div></section>
    <OsNetworkTargetOverview v-if="view==='overview'" :key="active.id+'-'+refreshKey" :target="active" @open="go" />
    <template v-if="view==='discovery'"><LinuxDiscovery v-if="active.provider==='ssh'" :key="active.id+'-'+refreshKey" :target-id="active.id" :profile-configured="!!active.os_network_profile"/><WindowsDiscovery v-if="active.provider==='winrm'" :key="active.id+'-'+refreshKey" :target-id="active.id" :profile-configured="!!active.os_network_profile"/><SnmpDiscovery v-if="active.provider==='snmp'" :key="active.id+'-'+refreshKey" :target-id="active.id" :profile-configured="!!active.os_network_profile"/><RouterOsDiscovery v-if="active.provider==='routeros'" :key="active.id+'-'+refreshKey" :target-id="active.id" :profile-configured="!!active.os_network_profile"/></template>
    <SystemEvents v-if="view==='events'" :key="active.id+'-'+refreshKey" :target-id="active.id"/>
    <ConnectorMetrics v-if="view==='metrics'" :key="active.id+'-'+refreshKey" :target-id="active.id"/>
    <NetworkConfigurationBackup v-if="view==='backups'" :key="active.id+'-'+refreshKey" :target-id="active.id" :profile-configured="!!active.os_network_profile" :can-backup="manage&&auth.can('network.configuration.backup')&&auth.can('inventory.assets.read')" @plans="go('plans')"/>
    <ConnectorLifecycle v-if="view==='plans'" :key="active.id+'-'+refreshKey" :target="active" :binding="binding" :operations="driver?.operations||[]" :collectors="collectors" :actions="[]" :write-actions="false" hide-actions :manage="manage" initial-tab="plans" v-model:busy="busy" @changed="refreshTarget"/>
    <section v-if="view==='topology'" class="panel"><h2>Наблюдаемая топология Workspace</h2><p>Источники и конфликты по всему Workspace. Для IPAM reconciliation выбирайте источник явно.</p><NuxtLink class="btn" :to="inventoryContextUrl('ipam',workspace)">Открыть IPAM</NuxtLink><NetworkDiscoveryTopology :key="workspace+'-'+active.id+'-'+refreshKey" :workspace-id="workspace" :initial-target-id="active.id" :can-write="manager&&auth.can('inventory.network.manage')" @update:mutating="busy=$event"/></section>
   </template>
  </template>
 </div>
</template>
<style scoped>
.os-stats,.os-providers{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:16px 0}.os-stat{display:flex;flex-direction:column;gap:8px;border:1px solid var(--border,#cbd5e1);border-radius:8px;padding:14px}.os-stat strong{font-size:1.8rem;font-variant-numeric:tabular-nums}.os-provider{display:flex;flex-direction:column;gap:6px;text-align:left;white-space:normal;align-items:flex-start}.os-provider span{font-size:.85rem}.inline-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
</style>
