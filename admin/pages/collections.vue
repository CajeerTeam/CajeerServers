<script setup lang="ts">
import { apiError } from '~/composables/useApi'
import { queryUuid,selectWorkspace,inventoryContextUrl } from '~/composables/inventoryNavigation'
import { collectionViews,collectionView,collectionUrl,scopedTarget,type CollectionView } from '~/composables/collectionNavigation'
import type { LifecycleTarget,LifecycleBinding,CollectorDefinition } from '~/composables/connectorLifecycle'
const api=useApi(),auth=useAuth(),route=useRoute()
type Workspace={id:string;name:string;role:string}
type Target=LifecycleTarget&{provider:string;endpoint_host:string;endpoint_port:number;health_status:string;proxmox_profile?:unknown}
type Driver={provider:string;purpose:string;credentialTypes:string[];operations:string[];collectors?:CollectorDefinition[]}
type Binding=LifecycleBinding&{state:string;type:string}
const spaces=ref<Workspace[]>([]),drivers=ref<Driver[]>([]),targets=ref<Target[]>([]),workspace=ref(''),selected=ref<Target|null>(null),binding=ref<Binding|null>(null)
const search=ref(''),provider=ref(''),offset=ref(0),more=ref(false),loading=ref(false),busy=ref(false),error=ref(''),view=ref<CollectionView>('collectors')
let sequence=0, disposed=false, initialized=false
let timer:ReturnType<typeof setInterval>|undefined
const allowed=computed(()=>collectionViews({read:auth.can('connectors.targets.read')&&auth.can('workspaces.read'),inventory:auth.can('inventory.assets.read'),ingestion:auth.can('ingestion.read'),transfer:auth.can('inventory.transfer.read')}))
const active=computed(()=>scopedTarget(selected.value,workspace.value))
const driver=computed(()=>drivers.value.find(d=>d.provider===active.value?.provider))
const manager=computed(()=>['owner','manager'].includes(spaces.value.find(s=>s.id===workspace.value)?.role||''))
const manage=computed(()=>manager.value&&auth.can('connectors.credentials.manage'))
const labels:Record<CollectionView,string>={collectors:'Коллекторы',plans:'Планы и запуски',metrics:'Метрики',ingestion:'Внешние данные и импорт',exports:'Экспорт'}
const connectorLink=computed(()=>inventoryContextUrl('connectors',workspace.value)+(active.value?'&target_id='+active.value.id:''))
const scriptsLink=computed(()=>inventoryContextUrl('scripts',workspace.value))
async function read<T>(path:string){return (await api.get<{data:T}>(path)).data}
function valid(seq:number){return !disposed&&seq===sequence}
async function load(requestedTarget='',page=0){
  const seq=++sequence,ws=workspace.value;loading.value=true;error.value='';targets.value=[];selected.value=null;binding.value=null;offset.value=page;more.value=false
  if(!ws){loading.value=false;return}
  try{
    const params=new URLSearchParams({workspace_id:ws,limit:'50',offset:String(page),...(search.value.trim()?{search:search.value.trim()}:{}),...(provider.value?{provider:provider.value}:{})})
    const rows=await read<Target[]>('/connectors/targets?'+params);if(!valid(seq))return;targets.value=rows;more.value=rows.length===50
    if(requestedTarget){const target=rows.find(t=>t.id===requestedTarget)||await read<Target>('/connectors/targets/'+requestedTarget);if(!valid(seq))return;if(!scopedTarget(target,ws)){error.value='Подключение не относится к выбранному Workspace.';return}await select(target,seq)}
  }catch(e){if(valid(seq))error.value=apiError(e).message}finally{if(valid(seq))loading.value=false}
}
async function select(target:Target,existingSequence?:number){
  if(busy.value||!scopedTarget(target,workspace.value))return
  const seq=existingSequence??++sequence;loading.value=true;error.value='';selected.value=null;binding.value=null
  try{
    const fresh=await read<Target>('/connectors/targets/'+target.id);if(!valid(seq))return
    if(!scopedTarget(fresh,workspace.value))return
    const d=drivers.value.find(d=>d.provider===fresh.provider)
    if(auth.can('connectors.credentials.read')){
      const rows=await read<Binding[]>(`/connectors/targets/${fresh.id}/credential-bindings`);if(!valid(seq))return
      binding.value=rows.find(b=>b.state==='active'&&!!d&&b.purpose===d.purpose&&d.credentialTypes.includes(b.type))||null
    }
    selected.value=fresh
  }catch(e){if(valid(seq))error.value=apiError(e).message}finally{if(valid(seq))loading.value=false}
}
async function go(next:CollectionView,target=active.value?.id||''){
  if(busy.value)return;view.value=collectionView(next,allowed.value);await navigateTo(collectionUrl(workspace.value,target,view.value),{replace:true})
}
async function choose(target:Target){await select(target);if(active.value?.id===target.id)await go(view.value,target.id)}
async function changeWorkspace(){if(busy.value)return;selected.value=null;binding.value=null;search.value='';provider.value='';await go(view.value,'');await load()}
async function browse(page:number){if(busy.value)return;selected.value=null;binding.value=null;await go(view.value,'');await load('',page)}
async function refreshTarget(){const t=active.value,seq=sequence;if(!t)return;try{const fresh=await read<Target>('/connectors/targets/'+t.id);if(valid(seq)&&active.value?.id===t.id&&scopedTarget(fresh,workspace.value)){selected.value=fresh;if(auth.can('connectors.credentials.read')){const d=drivers.value.find(x=>x.provider===fresh.provider);const rows=await read<Binding[]>(`/connectors/targets/${fresh.id}/credential-bindings`);if(valid(seq)&&active.value?.id===t.id)binding.value=rows.find(b=>b.state==='active'&&!!d&&b.purpose===d.purpose&&d.credentialTypes.includes(b.type))||null}}}catch(e){if(valid(seq)){const failure=apiError(e);error.value=failure.message;if(failure.status===403||failure.status===404){selected.value=null;binding.value=null}}}}
async function init(){if(!allowed.value.length)return;loading.value=true;try{const [w,d]=await Promise.all([read<Workspace[]>('/workspaces'),read<Driver[]>('/connectors/drivers')]);if(disposed)return;spaces.value=w;drivers.value=d;workspace.value=selectWorkspace(w,route.query.workspace_id);view.value=collectionView(route.query.view,allowed.value);await load(queryUuid(route.query.target_id));initialized=true}catch(e){if(!disposed)error.value=apiError(e).message}finally{if(!disposed)loading.value=false}}
watch(()=>route.query,async q=>{if(!initialized||disposed)return;const ws=selectWorkspace(spaces.value,q.workspace_id),id=queryUuid(q.target_id),next=collectionView(q.view,allowed.value);if(ws!==workspace.value||id!==(active.value?.id||'')){busy.value=false;workspace.value=ws;view.value=next;await load(id)}else if(next!==view.value){busy.value=false;view.value=next}},{deep:true})
onMounted(()=>{void init();timer=setInterval(()=>{if(!busy.value&&!loading.value&&!document.hidden)void refreshTarget()},15000)});onBeforeUnmount(()=>{disposed=true;sequence++;if(timer)clearInterval(timer)})
</script>
<template>
  <div data-testid="collection-center">
    <h1>Сбор данных</h1>
    <p class="muted">Коллекторы, планы, история, временные ряды, внешние события и обмен данными в одном рабочем пространстве.</p>
    <p v-if="!allowed.length" role="alert">Недостаточно прав для просмотра подключений.</p>
    <template v-else>
      <section class="panel">
        <div class="form-row"><label>Workspace<select v-model="workspace" data-testid="collection-workspace" :disabled="busy||loading" @change="changeWorkspace"><option v-for="w in spaces" :key="w.id" :value="w.id">{{w.name}}</option></select></label>
          <NuxtLink v-if="auth.can('scripts.read')" class="btn" :to="scriptsLink">Скрипты и подписи</NuxtLink><NuxtLink class="btn" :to="connectorLink">Настройки подключения и Vault</NuxtLink>
        </div>
        <form class="form-row" @submit.prevent="browse(0)"><label>Поиск подключения<input v-model="search" data-testid="collection-search" maxlength="200" /></label><label>Провайдер<select v-model="provider"><option value="">Все</option><option v-for="d in drivers" :key="d.provider" :value="d.provider">{{d.provider}}</option></select></label><button class="btn" :disabled="busy||loading">Найти</button></form>
        <p v-if="loading" role="status">Загрузка…</p><p v-if="error" role="alert">{{error}}</p>
        <div class="table-wrap"><table class="table"><thead><tr><th>Подключение</th><th>Провайдер / состояние</th><th>Действие</th></tr></thead><tbody><tr v-for="t in targets" :key="t.id"><td>{{t.name}}</td><td>{{t.provider}} · {{t.state}} · {{t.health_status}}</td><td><button class="btn" :aria-pressed="active?.id===t.id" :disabled="busy||loading" :data-testid="`collection-target-${t.id}`" @click="choose(t)">Выбрать</button></td></tr></tbody></table></div>
        <p v-if="!targets.length&&!loading">Нет подключений. Создайте target в настройках подключения.</p>
        <button class="btn" :disabled="busy||loading||offset===0" @click="browse(offset-50)">Назад</button> <button class="btn" :disabled="busy||loading||!more||offset>=10000" @click="browse(offset+50)">Далее</button>
      </section>
      <section class="panel" aria-label="Разделы сбора данных"><h2>{{active?active.name:'Выберите подключение'}}</h2><div class="inline-actions"><button v-for="v in allowed" :key="v" class="btn" :class="{primary:view===v}" :aria-pressed="view===v" :disabled="busy||loading" :data-testid="`collection-view-${v}`" @click="go(v)">{{labels[v]}}</button></div></section>
      <section v-if="view==='collectors'" class="panel" data-testid="collection-registry"><h2>Реестр коллекторов</h2><p class="muted">Доступные исполняемые коллекторы из Connector Registry. Выберите совместимое подключение для создания плана.</p><div class="table-wrap"><table class="table"><thead><tr><th>Коллектор</th><th>Провайдер</th><th>Операции</th><th>Ресурсы</th></tr></thead><tbody><template v-for="d in drivers" :key="d.provider"><tr v-for="c in d.collectors||[]" :key="c.name"><td>{{c.name}}</td><td>{{d.provider}}</td><td>{{c.operations.join(', ')}}</td><td>{{c.resource_types.join(', ')}}</td></tr></template></tbody></table></div><button v-if="active&&allowed.includes('plans')" class="btn primary" @click="go('plans')">Управлять планами выбранного подключения</button></section>
      <template v-if="active&&!loading">
        <ProxmoxClusterDiscovery v-if="view==='collectors'&&active.provider==='proxmox'&&auth.can('inventory.assets.read')" :key="active.id+'-cluster'" :target-id="active.id" :profile-configured="!!active.proxmox_profile" />
        <ProxmoxGuestDiscovery v-if="view==='collectors'&&active.provider==='proxmox'&&auth.can('inventory.assets.read')" :key="active.id+'-guests'" :target-id="active.id" :profile-configured="!!active.proxmox_profile" />
        <ProxmoxStorageNetworkDiscovery v-if="view==='collectors'&&active.provider==='proxmox'&&auth.can('inventory.assets.read')" :key="active.id+'-storage-network'" :target-id="active.id" :profile-configured="!!active.proxmox_profile" />
        <ProxmoxTaskHistory v-if="view==='collectors'&&active.provider==='proxmox'&&auth.can('inventory.assets.read')" :key="active.id+'-tasks'" :target-id="active.id" :profile-configured="!!active.proxmox_profile" />
        <ConnectorLifecycle v-if="view==='plans'" :key="active.id+'-plans'" :target="active" :binding="binding" :operations="driver?.operations||[]" :collectors="(driver?.collectors||[]).filter(c=>!['proxmox.cluster','proxmox.guests','proxmox.storage-network','proxmox.tasks'].includes(c.name)||!!active?.proxmox_profile)" :actions="[]" :write-actions="false" :manage="manage" initial-tab="plans" v-model:busy="busy" @changed="refreshTarget" />
        <ConnectorMetrics v-if="view==='metrics'" :key="active.id+'-metrics'" :target-id="active.id" />
        <ConnectorIngestion v-if="view==='ingestion'" :key="active.id+'-ingestion'" :target-id="active.id" :target="active" :binding="binding" :can-manage="manager&&auth.can('ingestion.manage')" />
        <ConnectorDataExport v-if="view==='exports'" :key="active.id+'-exports'" :target-id="active.id" :can-ingestion="auth.can('ingestion.read')" />
      </template>
    </template>
  </div>
</template>
