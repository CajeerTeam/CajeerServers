<script setup lang="ts">
import { apiError } from '~/composables/useApi'
import { inventoryContextUrl, inventoryAssetUrl, queryUuid } from '~/composables/inventoryNavigation'

const route = useRoute()
const api = useApi()
const auth = useAuth()
type Asset = {
  id:string; workspace_id:string; owner_user_id:string|null; name:string; type:string; status:string;
  source:string; external_id:string|null; os_family:string|null; environment:string;
  ip_addresses:string[]; tags:string[]; attributes:Record<string,unknown>; version:number; etag:string; deleted_at:string|null; updated_at:string;
}
type Workspace = { id:string; name:string; role:'owner'|'manager'|'viewer' }
type Relation = { id:string; source_asset_id:string; target_asset_id:string; kind:string }
type Interface = { id:string; asset_id:string; name:string; mac_address:string|null }
type Address = { id:string; interface_id:string|null; address:string; status:string }
type Revision = { asset_version:number; operation:string; origin:string; created_at:string }
type Binding = { id:string; credential_id:string; purpose:string }
const asset = ref<Asset|null>(null)
const workspace = ref<Workspace|null>(null)
const relations = ref<Relation[]>([]), interfaces = ref<Interface[]>([]), addresses = ref<Address[]>([])
const revisions = ref<Revision[]>([]), bindings = ref<Binding[]>([])
type HostHardware = {os_name:string;os_version:string;cpu_count:number;memory_bytes:number;disks:{name:string;size_bytes:number}[];interfaces:{name:string;addresses:string[]}[]}
const hardware = computed<HostHardware|null>(() => {
  const value = asset.value?.attributes?.host_hardware
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null
  const h = value as Partial<HostHardware>
  if (typeof h.os_name !== 'string' || typeof h.os_version !== 'string' || typeof h.cpu_count !== 'number' || typeof h.memory_bytes !== 'number') return null
  if (!Array.isArray(h.disks) || !Array.isArray(h.interfaces)) return null
  if (!h.disks.every(d=>d && typeof d.name==='string' && typeof d.size_bytes==='number') || !h.interfaces.every(i=>i && typeof i.name==='string' && Array.isArray(i.addresses) && i.addresses.every(a=>typeof a==='string'))) return null
  return h as HostHardware
})
const gib = (bytes:number) => (bytes / 1073741824).toLocaleString('ru-RU',{maximumFractionDigits:2}) + ' ГиБ'
const busy = ref(false), saving = ref(false), error = ref(''), notice = ref('')
const relatedErrors = ref<string[]>([])
const form = reactive({name:'',status:'unknown',environment:'unassigned',os_family:''})
const id = computed(() => queryUuid(route.params.id))
const canRead = computed(() => auth.can('inventory.assets.read'))
const canEdit = computed(() => asset.value?.source === 'manual' && !asset.value?.deleted_at &&
  ['owner','manager'].includes(workspace.value?.role||'') && auth.can('inventory.assets.update'))
const canDelete = computed(() => asset.value?.source === 'manual' &&
  ['owner','manager'].includes(workspace.value?.role||'') && auth.can('inventory.assets.delete'))
const detailLinks = computed(() => {
  const ws = asset.value?.workspace_id
  const aid = asset.value?.id
  return [
    {to:inventoryContextUrl('inventory',ws),title:'Реестр',permission:'inventory.assets.read'},
    {to:inventoryContextUrl('ipam',ws,aid),title:'Сети и IPAM',permission:'inventory.network.read'},
    {to:inventoryContextUrl('topology',ws,aid),title:'Топология',permission:'inventory.relationships.read'},
    {to:inventoryContextUrl('history',ws,aid),title:'История и provenance',permission:'inventory.history.read'},
    {to:inventoryContextUrl('catalog',ws,aid),title:'Теги и группы',permission:'inventory.catalog.read'},
    {to:inventoryContextUrl('transfer',ws,aid),title:'Импорт и credentials',permission:'inventory.transfer.read'},
  ].filter(item=>auth.can(item.permission))
})
function updateForm(a:Asset){Object.assign(form,{name:a.name,status:a.status,environment:a.environment,os_family:a.os_family||''})}
async function load() {
  if (!canRead.value) return
  if (!id.value) {error.value='Неверный идентификатор объекта.';return}
  busy.value=true;error.value='';relatedErrors.value=[];notice.value=''
  try {
    let result: {data:Asset}
    try {result=await api.get<{data:Asset}>(`/inventory/assets/${id.value}`)}
    catch (e) {
      if (apiError(e).status !== 404) throw e
      // The backend authorizes include_deleted only for workspace owner/manager.
      result=await api.get<{data:Asset}>(`/inventory/assets/${id.value}?include_deleted=1`)
    }
    asset.value=result.data;updateForm(result.data)
    const wsId=result.data.workspace_id
    try {
      const spaces=await api.get<{data:Workspace[]}>('/workspaces')
      workspace.value=spaces.data.find(w=>w.id===wsId)||null
    } catch {
      // Asset remains readable; only operations requiring workspace ownership are disabled.
      workspace.value=null
    }
    // Each optional section fails independently; an unavailable IPAM must not hide the asset.
    const optional: {name:string;permission:string;get:()=>Promise<void>}[] = [
      {name:'Связи',permission:'inventory.relationships.read',get:async()=>{relations.value=(await api.get<{data:Relation[]}>(`/inventory/relationships?workspace_id=${wsId}&limit=100`)).data.filter(r=>r.source_asset_id===id.value||r.target_asset_id===id.value)}},
      {name:'Интерфейсы',permission:'inventory.network.read',get:async()=>{interfaces.value=(await api.get<{data:Interface[]}>(`/ipam/interfaces?workspace_id=${wsId}&limit=100`)).data.filter(p=>p.asset_id===id.value);addresses.value=(await api.get<{data:Address[]}>(`/ipam/addresses?workspace_id=${wsId}&limit=100`)).data.filter(a=>interfaces.value.some(p=>p.id===a.interface_id))}},
      {name:'История',permission:'inventory.history.read',get:async()=>{revisions.value=(await api.get<{data:Revision[]}>(`/inventory/assets/${id.value}/history?limit=10`)).data}},
      {name:'Привязки',permission:'inventory.credentials.read',get:async()=>{bindings.value=(await api.get<{data:Binding[]}>(`/inventory/assets/${id.value}/credential-bindings`)).data}},
    ]
    await Promise.all(optional.filter(x=>auth.can(x.permission)).map(async x=>{try{await x.get()}catch{relatedErrors.value.push(x.name)}}))
  } catch (e) {asset.value=null;workspace.value=null;error.value=apiError(e).message}
  finally {busy.value=false}
}
async function save(){
  if (!canEdit.value || !asset.value || saving.value) return
  saving.value=true;error.value='';notice.value=''
  try {
    const updated=await api.write<{data:Asset}>('PATCH',`/inventory/assets/${asset.value.id}`,
      {name:form.name.trim(),status:form.status,environment:form.environment.trim(),os_family:form.os_family||null},
      true,{'If-Match':asset.value.etag})
    asset.value=updated.data;updateForm(updated.data);notice.value='Изменения сохранены.'

  }catch(e){error.value=apiError(e).status===412?'Конфликт версий: обновите данные и повторите изменение.':apiError(e).message}
  finally{saving.value=false}
}
async function removeOrRestore(){
  if(!canDelete.value||!asset.value||saving.value)return
  const current=asset.value
  if(!current.deleted_at && !confirm(`Удалить «${current.name}»?`))return
  saving.value=true;error.value='';notice.value=''
  try{
    const response=await api.write<{data:Asset}>(current.deleted_at?'POST':'DELETE',
      `/inventory/assets/${current.id}${current.deleted_at?'/restore':''}`,undefined,true,{'If-Match':current.etag})
    asset.value=response.data;updateForm(response.data);notice.value=current.deleted_at?'Объект восстановлен.':'Объект помечен удалённым.'
  }catch(e){error.value=apiError(e).status===412?'Версия изменилась. Обновите данные.':apiError(e).message}
  finally{saving.value=false}
}
watch(()=>route.params.id,()=>{asset.value=null;void load()})
onMounted(load)
</script>
<template>
  <div>
    <div class="inline-actions">
      <NuxtLink :to="inventoryContextUrl('inventory',asset?.workspace_id||queryUuid(route.query.workspace_id))" class="btn">← К реестру</NuxtLink>
      <button class="btn" type="button" :disabled="busy || saving" @click="load">Обновить данные</button>
    </div>
    <h1>Карточка инфраструктурного объекта</h1>
    <div v-if="error" role="alert" class="alert">{{error}}</div>
    <div v-if="notice" role="status" class="alert success">{{notice}}</div>
    <div v-if="!canRead" role="alert" class="alert">Недостаточно прав inventory.assets.read.</div>
    <p v-else-if="busy && !asset" role="status">Загрузка объекта…</p>
    <template v-else-if="asset">
      <section class="panel">
        <h2>{{asset.name}}</h2>
        <p class="page-subtitle">{{asset.type}} · {{asset.status}} · {{asset.source}} <span v-if="asset.deleted_at" class="tag bad">Удалён</span></p>
        <dl class="inventory-details">
          <div><dt>UUID</dt><dd class="mono">{{asset.id}}</dd></div>
          <div><dt>Workspace</dt><dd>{{workspace?.name||asset.workspace_id}} ({{workspace?.role||'—'}})</dd></div>
          <div><dt>ОС</dt><dd>{{asset.os_family||'—'}}</dd></div>
          <div><dt>Окружение</dt><dd>{{asset.environment}}</dd></div>
          <div><dt>Владелец</dt><dd class="mono">{{asset.owner_user_id||'—'}}</dd></div>
          <div><dt>Версия</dt><dd>{{asset.version}}</dd></div>
          <div><dt>Изменён</dt><dd>{{asset.updated_at}}</dd></div>
          <div><dt>IP (legacy)</dt><dd>{{asset.ip_addresses.join(', ')||'—'}}</dd></div>
          <div><dt>Теги</dt><dd>{{asset.tags.join(', ')||'—'}}</dd></div>
        </dl>
        <div class="inline-actions"><NuxtLink v-for="link in detailLinks" :key="link.to" :to="link.to" class="btn">{{link.title}}</NuxtLink></div>
      </section>
      <section v-if="hardware" class="panel">
        <h2>Обнаруженное оборудование</h2>
        <p class="page-subtitle">Последние принятые сведения SSH / WinRM. Интерфейсы ниже не изменяют ручной IPAM.</p>
        <dl class="inventory-details">
          <div><dt>ОС</dt><dd>{{ hardware.os_name }} · {{ hardware.os_version }}</dd></div>
          <div><dt>Логические CPU</dt><dd>{{ hardware.cpu_count }}</dd></div>
          <div><dt>RAM</dt><dd>{{ gib(hardware.memory_bytes) }}</dd></div>
        </dl>
        <h3>Диски</h3>
        <ul v-if="hardware.disks.length"><li v-for="disk in hardware.disks" :key="disk.name">{{ disk.name }} · {{ gib(disk.size_bytes) }}</li></ul>
        <p v-else class="muted">Нет обнаруженных дисков.</p>
        <h3>Сетевые интерфейсы</h3>
        <ul v-if="hardware.interfaces.length"><li v-for="nic in hardware.interfaces" :key="nic.name">{{ nic.name }} · {{ nic.addresses.join(', ') || 'без IP' }}</li></ul>
        <p v-else class="muted">Нет обнаруженных интерфейсов.</p>
      </section>
      <section v-if="canEdit" class="panel">
        <h2>Редактирование объекта</h2>
        <form @submit.prevent="save">
          <div class="form-row">
            <div class="field"><label for="detail-name">Имя</label><input id="detail-name" v-model="form.name" maxlength="255" required /></div>
            <div class="field"><label for="detail-status">Статус</label><select id="detail-status" v-model="form.status"><option v-for="s in ['unknown','online','offline','maintenance','provisioning','decommissioned']" :key="s" :value="s">{{s}}</option></select></div>
            <div class="field"><label for="detail-env">Окружение</label><input id="detail-env" v-model="form.environment" maxlength="64" pattern="[a-z][a-z0-9_-]*" required /></div>
            <div class="field"><label for="detail-os">ОС</label><select id="detail-os" v-model="form.os_family"><option value="">Не указана</option><option v-for="s in ['windows','debian','ubuntu','linux','other']" :key="s" :value="s">{{s}}</option></select></div>
          </div>
          <div class="inline-actions"><button class="btn primary" type="submit" :disabled="saving">Сохранить изменения</button><button class="btn" type="button" :disabled="saving" @click="updateForm(asset)">Отменить изменения</button></div>
        </form>
      </section>
      <section v-if="canDelete" class="panel">
        <h2>Жизненный цикл</h2>
        <p class="page-subtitle">Удаление и восстановление используют ETag: конкурентное изменение не будет потеряно.</p>
        <button class="btn" type="button" :disabled="saving" @click="removeOrRestore">{{asset.deleted_at?'Восстановить объект':'Удалить объект'}}</button>
      </section>
      <p v-if="relatedErrors.length" class="alert" role="status">Часть связанных данных недоступна: {{relatedErrors.join(', ')}}. Основная карточка доступна.</p>
      <section v-if="auth.can('inventory.network.read')" class="panel"><h2>Интерфейсы и IPAM <small>({{interfaces.length}})</small></h2>
        <div v-if="!interfaces.length" class="empty">Нет привязанных интерфейсов в загруженной выборке.</div>
        <ul v-else><li v-for="p in interfaces" :key="p.id">{{p.name}} · {{p.mac_address||'MAC не указан'}} · {{addresses.filter(a=>a.interface_id===p.id).map(a=>a.address).join(', ')||'нет IP'}}</li></ul>
        <NuxtLink :to="inventoryContextUrl('ipam',asset.workspace_id,asset.id)">Открыть IPAM</NuxtLink>
      </section>
      <section v-if="auth.can('inventory.relationships.read')" class="panel"><h2>Связи <small>({{relations.length}})</small></h2>
        <div v-if="!relations.length" class="empty">Нет связей в загруженной выборке.</div>
        <ul v-else><li v-for="r in relations" :key="r.id">{{r.kind}} · <NuxtLink :to="inventoryAssetUrl(r.source_asset_id===asset.id?r.target_asset_id:r.source_asset_id,asset.workspace_id)">{{r.source_asset_id===asset.id?r.target_asset_id:r.source_asset_id}}</NuxtLink></li></ul>
      </section>
      <section v-if="auth.can('inventory.history.read')" class="panel"><h2>Последние изменения</h2>
        <div v-if="!revisions.length" class="empty">Ревизий нет.</div>
        <ul v-else><li v-for="r in revisions" :key="r.asset_version">Версия {{r.asset_version}} — {{r.operation}} ({{r.origin}}), {{r.created_at}}</li></ul>
        <NuxtLink :to="inventoryContextUrl('history',asset.workspace_id,asset.id)">Вся история</NuxtLink>
      </section>
      <section v-if="auth.can('inventory.credentials.read')" class="panel"><h2>Привязки credentials</h2>
        <p class="page-subtitle">Секреты и их значения здесь не отображаются.</p>
        <ul v-if="bindings.length"><li v-for="b in bindings" :key="b.id">{{b.purpose}} · <span class="mono">{{b.credential_id}}</span></li></ul>
        <div v-else class="empty">Нет привязок.</div>
        <NuxtLink :to="inventoryContextUrl('transfer',asset.workspace_id,asset.id)">Управление привязками</NuxtLink>
      </section>
    </template>
  </div>
</template>
<style scoped>
.inventory-details{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;margin:16px 0}
.inventory-details div{min-width:0;border-bottom:1px solid var(--border,#8885);padding-bottom:8px}
.inventory-details dt{font-size:.85em;opacity:.7}.inventory-details dd{margin:4px 0;overflow-wrap:anywhere}
</style>
