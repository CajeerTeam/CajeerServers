<script setup lang="ts">
import { apiError } from '~/composables/useApi'
import { selectWorkspace, queryUuid, inventoryAssetUrl, inventoryContextUrl } from '~/composables/inventoryNavigation'
const route=useRoute()

const api=useApi(),auth=useAuth()
type Workspace={id:string;name:string;role:string}
type Asset={id:string;name:string;type:string}
type Batch={id:string;status:string;row_count:number;processed_rows:number;summary:Record<string,number>;results:{row_number:number;outcome:string;error_code?:string|null}[];queue_available?:boolean}
type Binding={id:string;asset_id:string;credential_id:string;purpose:string;created_at:string}
const spaces=ref<Workspace[]>([]),workspace=ref(''),assets=ref<Asset[]>([]),assetId=ref('')
const format=ref<'csv'|'json'>('csv'),content=ref(''),filename=ref(''),busy=ref(false),error=ref(''),notice=ref('')
const preview=ref<{total:number;summary:Record<string,number>;rows:{row:number;external_id:string;action:string}[]}|null>(null)
const batch=ref<Batch|null>(null),bindings=ref<Binding[]>([])
const credentialId=ref(''),purpose=ref('ssh'),scopeId=ref('')
const stableKey=ref('')
const initializing=ref(true)
let polling:ReturnType<typeof setInterval>|null=null
function resetImport(){preview.value=null;batch.value=null;stableKey.value=''}
function activeBatch(){return !!batch.value && ['pending','enqueued','queued','processing','running'].includes(batch.value.status)}
const canRead=computed(()=>auth.can('inventory.transfer.read'))
const canManage=computed(()=>auth.can('inventory.transfer.manage')&&['owner','manager'].includes(spaces.value.find(x=>x.id===workspace.value)?.role||''))
const canCredentialRead=computed(()=>auth.can('inventory.credentials.read'))
const canCredentialManage=computed(()=>auth.can('inventory.credentials.manage')&&canManage.value)
const canScope=computed(()=>auth.can('inventory.credentials.scope')&&spaces.value.find(x=>x.id===workspace.value)?.role==='owner')
function idempotencyKey(){if(!stableKey.value)stableKey.value=crypto.randomUUID();return stableKey.value}
function query(){return new URLSearchParams({workspace_id:workspace.value})}
function fail(e:unknown){error.value=apiError(e).message}
async function initialize(){
  if(!canRead.value)return
  try{spaces.value=(await api.get<{data:Workspace[]}>('/workspaces')).data;workspace.value=selectWorkspace(spaces.value,route.query.workspace_id);await loadAssets()}
  catch(e){fail(e)}
  finally{initializing.value=false}
}
async function loadAssets(){
  const requested=queryUuid(route.query.asset_id)
  assets.value=[];assetId.value='';bindings.value=[];resetImport()
  if(!workspace.value||!auth.can('inventory.assets.read'))return
  try{assets.value=(await api.get<{data:Asset[]}>(`/inventory/assets?${new URLSearchParams({workspace_id:workspace.value,limit:'100'})}`)).data
    if(requested&&assets.value.some(a=>a.id===requested))assetId.value=requested}
  catch(e){fail(e)}
}
async function selectFile(event:Event){
  const files=(event.target as HTMLInputElement).files
  const file=files?.[0];if(!file)return
  if(file.size>524288){error.value='Размер файла больше 512 КиБ.';return}
  format.value=file.name.toLowerCase().endsWith('.json')?'json':'csv'
  content.value=await file.text();filename.value=file.name;resetImport();error.value=''
}
async function inspect(){
  if(!canManage.value||!workspace.value||!content.value)return
  busy.value=true;error.value='';notice.value=''
  try{
    const result=await api.write<{data:typeof preview.value}>('POST','/inventory/imports/preview',{workspace_id:workspace.value,format:format.value,content:content.value})
    preview.value=result.data
  }catch(e){fail(e)}finally{busy.value=false}
}
async function submit(){
  if(!canManage.value||!content.value||!workspace.value||batch.value)return
  busy.value=true;error.value='';notice.value=''
  try{
    const result=await api.write<{data:Batch}>('POST','/inventory/imports',{workspace_id:workspace.value,format:format.value,content:content.value},true,{'Idempotency-Key':idempotencyKey()})
    batch.value=result.data;notice.value=result.data.queue_available===false?'Задание сохранено, но очередь недоступна. Нажмите «Повторить отправку».':'Импорт поставлен в очередь.'
  }catch(e){fail(e)}finally{busy.value=false}
}
async function refreshBatch(){
  if(!batch.value)return
  busy.value=true;error.value=''
  try{batch.value=(await api.get<{data:Batch}>(`/inventory/imports/${batch.value.id}?${query()}`)).data}
  catch(e){fail(e)}finally{busy.value=false}
}
async function dispatch(){
  if(!batch.value||!canManage.value)return
  busy.value=true;error.value=''
  try{await api.write('POST',`/inventory/imports/${batch.value.id}/dispatch?${query()}`);await refreshBatch();notice.value='Задание отправлено в очередь.'}
  catch(e){fail(e)}finally{busy.value=false}
}
async function downloadExport(fileFormat:'csv'|'json'){
  if(!canRead.value||!workspace.value)return
  error.value='';busy.value=true
  try{
    const path=`/api/v1/inventory/exports?${new URLSearchParams({workspace_id:workspace.value,format:fileFormat})}`
    const response=await fetch(path,{credentials:'include',cache:'no-store',headers:{Accept:'*/*'}})
    if(!response.ok)throw new Error(`Export HTTP ${response.status}`)
    const data=await response.blob(),url=URL.createObjectURL(data)
    try{const a=document.createElement('a');a.href=url;a.download=`cajeer-inventory.${fileFormat}`;a.click()}
    finally{URL.revokeObjectURL(url)}
  }catch(e){error.value=e instanceof Error?e.message:'Ошибка экспорта.'}finally{busy.value=false}
}
async function loadBindings(){
  bindings.value=[];if(!assetId.value||!canCredentialRead.value)return
  try{bindings.value=(await api.get<{data:Binding[]}>(`/inventory/assets/${assetId.value}/credential-bindings`)).data}
  catch(e){fail(e)}
}
async function scope(){
  if(!canScope.value)return
  busy.value=true;error.value=''
  try{await api.write('POST','/inventory/credentials/scopes',{workspace_id:workspace.value,credential_id:scopeId.value});notice.value='Существующий credential закреплён за пространством.'}
  catch(e){fail(e)}finally{busy.value=false}
}
async function attach(){
  if(!canCredentialManage.value||!assetId.value)return
  busy.value=true;error.value=''
  try{await api.write('POST',`/inventory/assets/${assetId.value}/credential-bindings`,{credential_id:credentialId.value,purpose:purpose.value});notice.value='Привязка добавлена.';await loadBindings()}
  catch(e){fail(e)}finally{busy.value=false}
}
async function detach(binding:Binding){
  if(!canCredentialManage.value||!confirm('Удалить привязку credential?'))return
  busy.value=true;error.value=''
  try{await api.write('DELETE',`/inventory/assets/${assetId.value}/credential-bindings/${binding.id}`);await loadBindings()}
  catch(e){fail(e)}finally{busy.value=false}
}
watch(workspace,()=>{if(initializing.value)return;void navigateTo(inventoryContextUrl('transfer',workspace.value),{replace:true});loadAssets()})
onMounted(()=>{polling=setInterval(()=>{if(activeBatch()&&!busy.value&&document.visibilityState==='visible')void refreshBatch()},3000)})
onUnmounted(()=>{if(polling)clearInterval(polling)})
watch(assetId,loadBindings)
onMounted(initialize)
</script>
<template>
  <div>
    <h1>Импорт, экспорт и привязки credentials</h1>
    <p class="page-subtitle">CSV/JSON: предварительная проверка, очередь и идемпотентная сверка. Секреты не передаются в UI.</p>
    <div v-if="error" class="alert" role="alert">{{error}}</div>
    <div v-if="notice" class="alert success" role="status">{{notice}}</div>
    <section v-if="!canRead" class="panel">Недостаточно прав inventory.transfer.read.</section>
    <template v-else>
      <section class="panel">
        <h2>Пространство</h2>
        <label for="transfer-workspace">Workspace</label>
        <select id="transfer-workspace" v-model="workspace"><option v-for="w in spaces" :key="w.id" :value="w.id">{{w.name}} ({{w.role}})</option></select>
        <p>Экспорт ограничен 500 объектами; не включает attributes и сведения о credentials.</p>
        <div class="inline-actions"><button type="button" class="btn" :disabled="busy||!workspace" @click="downloadExport('csv')">Экспорт CSV</button>
          <button type="button" class="btn" :disabled="busy||!workspace" @click="downloadExport('json')">Экспорт JSON</button></div>
      </section>
      <section v-if="canManage" class="panel">
        <h2>Импорт</h2>
        <p>До 512 КиБ и 200 записей. CSV: external_id,type,name,status,os_family,environment,ip_addresses,tags. Массивы в ячейках задаются JSON.</p>
        <label for="transfer-file">Файл</label><input id="transfer-file" type="file" accept=".csv,.json,text/csv,application/json" @change="selectFile" />
        <p v-if="filename">{{filename}} ({{format.toUpperCase()}})</p>
        <div class="inline-actions"><button type="button" class="btn" :disabled="busy||!content" @click="inspect">Предпросмотр</button>
          <button type="button" class="btn" :disabled="busy||!preview||!!batch" @click="submit">Отправить в очередь</button></div>
        <div v-if="preview">
          <p>Строк: {{preview.total}}; предварительная оценка: {{JSON.stringify(preview.summary)}}.</p>
          <table><thead><tr><th>Строка</th><th>External ID</th><th>Действие</th></tr></thead>
            <tbody><tr v-for="row in preview.rows" :key="row.row"><td>{{row.row}}</td><td>{{row.external_id}}</td><td>{{row.action}}</td></tr></tbody></table>
        </div>
        <div v-if="batch" class="panel">
          <h3>Импорт {{batch.id}}</h3><p role="status" aria-live="polite">Статус: {{batch.status}}; {{batch.processed_rows}} / {{batch.row_count}}. {{JSON.stringify(batch.summary)}}</p><progress :value="batch.processed_rows" :max="Math.max(1,batch.row_count)" style="width:100%" />
          <div class="inline-actions"><button type="button" class="btn" :disabled="busy" @click="refreshBatch">Обновить результат</button>
            <button v-if="batch.status==='pending'" type="button" class="btn" :disabled="busy" @click="dispatch">Повторить отправку</button></div>
          <table><thead><tr><th>Строка</th><th>Результат</th><th>Код ошибки</th></tr></thead>
            <tbody><tr v-for="row in batch.results" :key="row.row_number"><td>{{row.row_number}}</td><td>{{row.outcome}}</td><td>{{row.error_code||'—'}}</td></tr></tbody></table>
        </div>
      </section>
      <section v-if="canCredentialRead&&auth.can('inventory.assets.read')" class="panel">
        <h2>Привязки credentials</h2>
        <p>Только идентификаторы существующих секретов. Содержимое зашифрованных записей не извлекается.</p>
        <label for="credential-asset">Объект</label> <NuxtLink v-if="assetId" :to="inventoryAssetUrl(assetId,workspace)">Карточка объекта</NuxtLink><select id="credential-asset" v-model="assetId"><option value="">Выбрать объект</option><option v-for="a in assets" :key="a.id" :value="a.id">{{a.name}} ({{a.type}})</option></select>
        <ul><li v-for="b in bindings" :key="b.id">{{b.purpose}} — {{b.credential_id}}
          <button v-if="canCredentialManage" class="btn" type="button" :disabled="busy" @click="detach(b)">Отвязать</button></li></ul>
        <form v-if="canCredentialManage&&assetId" @submit.prevent="attach">
          <label for="credential-id">Credential UUID</label><input id="credential-id" v-model="credentialId" required />
          <label for="credential-purpose">Назначение</label><input id="credential-purpose" v-model="purpose" required />
          <button class="btn" :disabled="busy">Добавить привязку</button>
        </form>
        <form v-if="canScope" @submit.prevent="scope">
          <h3>Закрепить существующий credential за workspace</h3>
          <label for="scope-id">Credential UUID</label><input id="scope-id" v-model="scopeId" required />
          <button class="btn" :disabled="busy">Закрепить</button>
        </form>
      </section>
    </template>
  </div>
</template>
