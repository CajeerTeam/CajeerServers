<script setup lang="ts">
import { apiError } from '~/composables/useApi'
import { selectWorkspace, queryUuid, inventoryAssetUrl, inventoryContextUrl } from '~/composables/inventoryNavigation'
const route=useRoute()
const api = useApi(), auth = useAuth()
type Workspace={id:string;name:string;role:string}
type Asset={id:string;name:string;type:string;source:string;external_id:string|null;etag:string;version:number;deleted_at:string|null}
type Revision={asset_version:number;operation:string;origin:string;actor_id:string|null;created_at:string;changes:Record<string,unknown>;after_state:Record<string,unknown>}
type Provenance={field:string;mode:string;source:string;observed_at:string|null}
type Conflict={id:string;field:string;observed_value:unknown;seen_count:number;last_seen_at:string}
type Observation={id:string;event_key:string;outcome:string;received_at:string;applied_fields:string[];conflicted_fields:string[]}
const spaces=ref<Workspace[]>([]),workspace=ref(''),assets=ref<Asset[]>([]),assetId=ref('')
const revisions=ref<Revision[]>([]),origins=ref<Provenance[]>([]),conflicts=ref<Conflict[]>([]),observations=ref<Observation[]>([])
const busy=ref(false),error=ref(''),notice=ref(''),historyTotal=ref(0),offset=ref(0)
const initializing=ref(true)
const sourceId=ref(''),eventKey=ref(''),observedName=ref(''),observedStatus=ref('unknown'),observedType=ref('server')
const overrideField=ref('name'),overrideValue=ref(''),restoreVersion=ref('')
const canRead=computed(()=>auth.can('inventory.history.read')&&auth.can('inventory.assets.read'))
const canManage=computed(()=>auth.can('inventory.reconcile.manage')&&canRead.value&&['owner','manager'].includes(spaces.value.find(s=>s.id===workspace.value)?.role||''))
const canRestore=computed(()=>auth.can('inventory.history.restore')&&canRead.value&&['owner','manager'].includes(spaces.value.find(s=>s.id===workspace.value)?.role||''))
const asset=computed(()=>assets.value.find(a=>a.id===assetId.value))
const fieldNames=['name','status','os_family','environment']
async function start(){
  if(!canRead.value)return
  try{spaces.value=(await api.get<{data:Workspace[]}>('/workspaces')).data;workspace.value=selectWorkspace(spaces.value,route.query.workspace_id);await loadAssets()}
  catch(e){error.value=apiError(e).message}
  finally{initializing.value=false}
}
async function loadAssets(){
  if(!workspace.value)return
  busy.value=true;error.value=''
  const requested=queryUuid(route.query.asset_id)
  try{assets.value=(await api.get<{data:Asset[]}>(`/inventory/assets?${new URLSearchParams({workspace_id:workspace.value,limit:'100'})}`)).data
    if(!assets.value.some(a=>a.id===assetId.value))assetId.value=assets.value.find(a=>a.id===requested)?.id||assets.value[0]?.id||''
    await loadHistory()
  }catch(e){error.value=apiError(e).message}finally{busy.value=false}
}
async function loadHistory(){
  if(!assetId.value){revisions.value=[];origins.value=[];conflicts.value=[];observations.value=[];return}
  error.value=''
  try{
    const id=encodeURIComponent(assetId.value)
    const [h,p,c,o]=await Promise.all([
      api.get<{data:Revision[];pagination:{total:number}}>(`/inventory/assets/${id}/history?limit=30&offset=${offset.value}`),
      api.get<{data:{fields:Provenance[]}}>(`/inventory/assets/${id}/provenance`),
      api.get<{data:Conflict[]}>(`/inventory/assets/${id}/conflicts`),
      api.get<{data:Observation[]}>(`/inventory/assets/${id}/observations`)
    ])
    revisions.value=h.data;historyTotal.value=h.pagination.total;origins.value=p.data.fields;conflicts.value=c.data;observations.value=o.data
  }catch(e){error.value=apiError(e).message}
}
async function refresh(){await loadAssets()}
async function observe(){
  if(!canManage.value||!workspace.value)return
  busy.value=true;error.value='';notice.value=''
  try{
    const data={workspace_id:workspace.value,source:'import',external_id:sourceId.value,event_key:eventKey.value,type:observedType.value,fields:{name:observedName.value,status:observedStatus.value}}
    const result=await api.write<{data:{outcome:string;duplicate:boolean}}>('POST','/inventory/observations',data)
    notice.value=`Наблюдение: ${result.data.outcome}${result.data.duplicate?' (повтор)':''}`
    await loadAssets()
  }catch(e){error.value=apiError(e).message}finally{busy.value=false}
}
async function override(){
  if(!canManage.value||!asset.value)return
  busy.value=true;error.value='';notice.value=''
  try{
    const value=overrideField.value==='os_family' && !overrideValue.value?null:overrideValue.value
    await api.write('PATCH',`/inventory/assets/${asset.value.id}/overrides`,{fields:{[overrideField.value]:value}},true,{'If-Match':asset.value.etag})
    notice.value='Ручное значение сохранено.';await refresh()
  }catch(e){error.value=apiError(e).status===412?'Версия объекта изменилась. Обновите данные.':apiError(e).message}finally{busy.value=false}
}
async function clearOverride(field:string){
  if(!canManage.value||!asset.value||!confirm(`Снять ручное переопределение ${field}?`))return
  busy.value=true;error.value=''
  try{
    await api.write('DELETE',`/inventory/assets/${asset.value.id}/overrides/${field}`,undefined,true,{'If-Match':asset.value.etag})
    notice.value='Переопределение снято; следующее наблюдение сможет обновить поле.';await refresh()
  }catch(e){error.value=apiError(e).message}finally{busy.value=false}
}
async function restore(){
  if(!canRestore.value||!asset.value||asset.value.source!=='manual'||!confirm('Восстановить поля из исторической версии? Атрибуты JSON не восстанавливаются.'))return
  busy.value=true;error.value=''
  try{
    await api.write('POST',`/inventory/assets/${asset.value.id}/restore-version`,{revision:Number(restoreVersion.value)},true,{'If-Match':asset.value.etag})
    notice.value='Исторические поля восстановлены как новая версия.';await refresh()
  }catch(e){error.value=apiError(e).message}finally{busy.value=false}
}
function formatJson(value:unknown){return JSON.stringify(value,null,2)}
watch(workspace,()=>{if(initializing.value)return;assetId.value='';offset.value=0;void navigateTo(inventoryContextUrl('history',workspace.value),{replace:true});loadAssets()})
watch(assetId,()=>{offset.value=0;loadHistory()})
onMounted(start)
</script>
<template>
  <div>
    <h1>История и происхождение данных CMDB</h1>
    <p class="muted">Версии из PostgreSQL, источники полей, конфликты ручных значений и повторные наблюдения.</p>
    <div v-if="error" class="alert bad" role="alert">{{ error }}</div>
    <div v-if="notice" class="alert success" role="status">{{ notice }}</div>
    <div v-if="!canRead" class="alert">Нет разрешения inventory.history.read.</div>
    <template v-else>
      <section class="panel">
        <div class="form-row">
          <div class="field"><label for="history-space">Пространство</label><select id="history-space" v-model="workspace" :disabled="busy"><option v-for="s in spaces" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
          <div class="field"><label for="history-asset">Объект</label><select id="history-asset" v-model="assetId" :disabled="busy"><option v-for="a in assets" :key="a.id" :value="a.id">{{ a.name }} ({{ a.source }})</option></select></div>
        </div>
        <button class="btn" :disabled="busy" @click="refresh">Обновить</button> <NuxtLink v-if="assetId" class="btn" :to="inventoryAssetUrl(assetId,workspace)">Карточка объекта</NuxtLink>
      </section>
      <section v-if="assetId" class="panel">
        <h2>История версий</h2>
        <div v-if="!revisions.length" class="empty">Нет записей.</div>
        <div v-for="r in revisions" :key="r.asset_version" class="panel" style="margin-bottom:12px">
          <strong>Версия {{ r.asset_version }} — {{ r.operation }}</strong> <span class="muted">{{ r.created_at }} · {{ r.origin }}</span>
          <pre class="mono" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ formatJson(r.changes) }}</pre>
        </div>
        <div class="inline-actions"><button class="btn" :disabled="offset===0" @click="offset-=30;loadHistory()">Назад</button><span>{{ offset+1 }}–{{ Math.min(offset+30,historyTotal) }} из {{ historyTotal }}</span><button class="btn" :disabled="offset+30>=historyTotal" @click="offset+=30;loadHistory()">Далее</button></div>
        <form v-if="canRestore && asset?.source==='manual' && !asset?.deleted_at" @submit.prevent="restore">
          <div class="field"><label for="history-restore">Восстановить безопасные поля из версии</label><input id="history-restore" v-model="restoreVersion" type="number" min="1" required /></div>
          <button class="btn" :disabled="busy">Восстановить как новую версию</button>
        </form>
      </section>
      <section v-if="assetId" class="panel">
        <h2>Provenance полей</h2>
        <div class="table-wrap"><table class="table"><thead><tr><th>Поле</th><th>Режим</th><th>Источник</th><th>Наблюдение</th><th></th></tr></thead><tbody>
          <tr v-for="p in origins" :key="p.field"><td>{{p.field}}</td><td>{{p.mode}}</td><td>{{p.source}}</td><td>{{p.observed_at||'—'}}</td><td><button v-if="p.mode==='manual'&&asset?.source==='import'&&canManage" class="btn" :disabled="busy" @click="clearOverride(p.field)">Снять override</button></td></tr>
        </tbody></table></div>
        <h3>Открытые конфликты</h3>
        <div v-if="!conflicts.length" class="muted">Нет конфликтов.</div>
        <div v-for="c in conflicts" :key="c.id" class="alert">{{ c.field }}: наблюдалось {{ formatJson(c.observed_value) }} · {{ c.seen_count }} раз</div>
        <h3>Последние наблюдения</h3>
        <div v-for="o in observations" :key="o.id" class="muted">{{o.event_key}} — {{o.outcome}} · {{o.received_at}}</div>
      </section>
      <section v-if="canManage" class="panel">
        <h2>Принять наблюдение импорта</h2>
        <p class="muted">Только источник <code>import</code>; ключ события необходим для идемпотентной обработки. Управляющие команды на сервер не отправляются.</p>
        <form @submit.prevent="observe"><div class="form-row">
          <div class="field"><label for="obs-id">Внешний ID</label><input id="obs-id" v-model="sourceId" required maxlength="255" /></div>
          <div class="field"><label for="obs-key">Ключ события</label><input id="obs-key" v-model="eventKey" required maxlength="120" /></div>
          <div class="field"><label for="obs-type">Тип</label><select id="obs-type" v-model="observedType"><option v-for="x in ['server','hypervisor_node','cluster','virtual_machine','container','router','workstation','other']" :key="x" :value="x">{{ x }}</option></select></div>
          <div class="field"><label for="obs-name">Имя</label><input id="obs-name" v-model="observedName" required /></div>
          <div class="field"><label for="obs-status">Статус</label><select id="obs-status" v-model="observedStatus"><option v-for="x in ['unknown','online','offline','maintenance','provisioning','decommissioned']" :key="x" :value="x">{{ x }}</option></select></div>
        </div><button class="btn primary" :disabled="busy||!workspace">Сохранить наблюдение</button></form>
        <template v-if="asset?.source==='import'"><h3>Ручное переопределение</h3>
          <form @submit.prevent="override"><div class="form-row">
            <div class="field"><label for="override-field">Поле</label><select id="override-field" v-model="overrideField"><option v-for="x in fieldNames" :key="x" :value="x">{{ x }}</option></select></div>
            <div class="field"><label for="override-value">Значение</label><input id="override-value" v-model="overrideValue" /></div>
          </div><button class="btn" :disabled="busy">Сохранить override</button></form>
        </template>
      </section>
    </template>
  </div>
</template>
