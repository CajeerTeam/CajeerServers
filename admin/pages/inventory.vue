<script setup lang="ts">
import { apiError } from '~/composables/useApi'
const api = useApi()
const auth = useAuth()
type Workspace = { id: string; name: string; organization_id: string; role: 'owner'|'manager'|'viewer' }
type Asset = {
  id: string; workspace_id: string; name: string; type: string; status: string; source: string;
  os_family: string|null; ip_addresses: string[]; tags: string[]; attributes: Record<string,unknown>;
  owner_user_id: string|null; etag: string; version: number; deleted_at: string|null; updated_at: string
}
type Paged = { data: Asset[]; pagination: { total:number; limit:number; offset:number } }
const types = ['server','hypervisor_node','cluster','virtual_machine','container','router','workstation','other']
const statuses = ['unknown','online','offline','maintenance','provisioning','decommissioned']
const systems = ['windows','debian','ubuntu','linux','other']
const spaces = ref<Workspace[]>([])
const workspaceId = ref('')
const assets = ref<Asset[]>([])
const selected = ref<Asset|null>(null)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const search = ref('')
const typeFilter = ref('')
const showDeleted = ref(false)
const offset = ref(0)
const total = ref(0)
const limit = 25
const form = reactive({ type: 'server', name:'', status:'unknown', os_family:'', owner_user_id:'', ips:'', tags:'', attributes:'{}' })
const activeWorkspace = computed(()=>spaces.value.find(w=>w.id===workspaceId.value))
const writable = computed(()=>['owner','manager'].includes(activeWorkspace.value?.role || ''))
const canRead = computed(()=>auth.can('inventory.assets.read'))
const canCreate = computed(()=>writable.value && auth.can('inventory.assets.create'))
const canUpdate = computed(()=>writable.value && auth.can('inventory.assets.update'))
const canDelete = computed(()=>writable.value && auth.can('inventory.assets.delete'))
function toForm(asset?:Asset) {
  selected.value = asset || null
  Object.assign(form,asset ? {
    type:asset.type, name:asset.name, status:asset.status, os_family:asset.os_family||'', owner_user_id:asset.owner_user_id||'',
    ips:asset.ip_addresses.join(', '), tags:asset.tags.join(', '), attributes:JSON.stringify(asset.attributes,null,2),
  } : { type:'server',name:'',status:'unknown',os_family:'',owner_user_id:'',ips:'',tags:'',attributes:'{}' })
  error.value='';notice.value=''
}
async function init() {
  if (!canRead.value) return
  busy.value=true
  try {
    spaces.value = (await api.get<{data:Workspace[]}>('/workspaces')).data
    if (!spaces.value.some(w=>w.id===workspaceId.value)) workspaceId.value=spaces.value[0]?.id||''
    await load()
  } catch(e) {error.value=apiError(e).message} finally {busy.value=false}
}
async function load() {
  if (!workspaceId.value) {assets.value=[];total.value=0;return}
  busy.value=true;error.value=''
  try {
    const qs = new URLSearchParams({ workspace_id:workspaceId.value,limit:String(limit),offset:String(offset.value) })
    if(search.value.trim()) qs.set('search',search.value.trim())
    if(typeFilter.value) qs.set('type',typeFilter.value)
    if(showDeleted.value && writable.value) qs.set('include_deleted','1')
    const result=await api.get<Paged>(`/inventory/assets?${qs}`)
    assets.value=result.data;total.value=result.pagination.total
  } catch(e) {error.value=apiError(e).message} finally {busy.value=false}
}
function parseForm() {
  const attributes=JSON.parse(form.attributes)
  if (attributes === null || Array.isArray(attributes) || typeof attributes !== 'object') throw new Error('Атрибуты должны быть JSON-объектом.')
  return {
    name:form.name.trim(),status:form.status,os_family:form.os_family||null,owner_user_id:form.owner_user_id||null,
    ip_addresses:form.ips.split(',').map(v=>v.trim()).filter(Boolean),
    tags:form.tags.split(',').map(v=>v.trim()).filter(Boolean),attributes,
  }
}
async function save() {
  if(!canCreate.value && !selected.value || selected.value && !canUpdate.value) return
  busy.value=true;error.value='';notice.value=''
  try {
    const payload=parseForm()
    if(selected.value) {
      const updated=await api.write<{data:Asset}>('PATCH',`/inventory/assets/${selected.value.id}`,payload,true,{'If-Match':selected.value.etag})
      selected.value=updated.data;notice.value='Изменения сохранены.'
    } else {
      const created=await api.write<{data:Asset}>('POST','/inventory/assets',{workspace_id:workspaceId.value,type:form.type,...payload})
      selected.value=created.data;notice.value='Объект создан.'
    }
    await load()
  } catch(e) {error.value=e instanceof SyntaxError?'Ошибка JSON в атрибутах.':apiError(e).status===412?'Объект изменён другим пользователем. Перезагрузите список.':e instanceof Error && e.message.includes('JSON-объектом')?e.message:apiError(e).message}
  finally {busy.value=false}
}
async function toggleDeleted(asset:Asset) {
  if(!canDelete.value)return
  if(!asset.deleted_at && !confirm(`Удалить «${asset.name}»? Объект можно восстановить.`)) return
  busy.value=true;error.value='';notice.value=''
  try {
    const method=asset.deleted_at?'POST':'DELETE'
    const path=`/inventory/assets/${asset.id}${asset.deleted_at?'/restore':''}`
    await api.write(method,path,undefined,true,{'If-Match':asset.etag})
    if(selected.value?.id===asset.id)toForm()
    notice.value=asset.deleted_at?'Объект восстановлен.':'Объект удалён.'
    await load()
  } catch(e) {error.value=apiError(e).status===412?'Конфликт версии. Обновите список.':apiError(e).message}
  finally {busy.value=false}
}
function switchWorkspace(){offset.value=0;toForm();load()}
function applyFilters(){offset.value=0;load()}
function page(dir:number){offset.value=Math.max(0,offset.value+dir*limit);load()}
onMounted(init)
</script>

<template>
  <div>
    <h1>Inventory / CMDB</h1>
    <p class="page-subtitle">Учёт инфраструктуры с изоляцией по рабочим пространствам. Версия 0.2.3 — ручное создание и управление объектами. Proxmox/VMmanager здесь не исполняются.</p>
    <div v-if="error" class="alert" role="alert">{{ error }}</div>
    <div v-if="notice" class="alert success" role="status">{{ notice }}</div>
    <div v-if="!canRead" class="alert">Нет разрешения inventory.assets.read.</div>
    <template v-else>
      <section class="panel">
        <div class="form-row">
          <div class="field"><label for="inventory-workspace">Пространство</label><select id="inventory-workspace" v-model="workspaceId" :disabled="busy" @change="switchWorkspace"><option v-for="w in spaces" :key="w.id" :value="w.id">{{ w.name }} ({{ w.role }})</option></select></div>
          <div class="field"><label for="inventory-search">Поиск по имени</label><input id="inventory-search" v-model="search" maxlength="100" placeholder="Имя сервера" @keyup.enter="applyFilters" /></div>
          <div class="field"><label for="inventory-type">Тип</label><select id="inventory-type" v-model="typeFilter" @change="applyFilters"><option value="">Все типы</option><option v-for="t in types" :key="t" :value="t">{{ t }}</option></select></div>
        </div>
        <div class="inline-actions"><button class="btn" type="button" :disabled="busy" @click="applyFilters">Обновить</button><button v-if="canCreate" class="btn primary" type="button" :disabled="busy" @click="toForm()">Новый объект</button><label v-if="writable"><input v-model="showDeleted" type="checkbox" style="width:auto" @change="applyFilters" /> Показывать удалённые</label></div>
        <div v-if="!assets.length" class="empty">Объекты не найдены.</div>
        <div v-else class="table-wrap"><table class="table"><thead><tr><th>Имя</th><th>Тип / статус</th><th>Адреса</th><th>Версия</th><th>Действия</th></tr></thead><tbody>
          <tr v-for="a in assets" :key="a.id"><td>{{ a.name }} <span v-if="a.deleted_at" class="tag bad">удалён</span><div class="muted small mono">{{ a.id }}</div></td><td>{{ a.type }} / {{ a.status }}</td><td>{{ a.ip_addresses.join(', ') || '—' }}</td><td>{{ a.version }}</td><td><div class="inline-actions"><button v-if="canUpdate && !a.deleted_at && a.source==='manual'" class="btn" :disabled="busy" @click="toForm(a)">Изменить</button><button v-if="canDelete && a.source==='manual'" class="btn" :disabled="busy" @click="toggleDeleted(a)">{{ a.deleted_at?'Восстановить':'Удалить' }}</button></div></td></tr>
        </tbody></table></div>
        <div class="inline-actions"><span class="muted">Всего: {{ total }}</span><button class="btn" :disabled="busy || offset===0" @click="page(-1)">Назад</button><button class="btn" :disabled="busy || offset+limit>=total" @click="page(1)">Далее</button></div>
      </section>
      <section v-if="canCreate || (selected && canUpdate)" class="panel">
        <h2>{{ selected?'Редактировать объект':'Новый объект' }}</h2>
        <form @submit.prevent="save">
          <div class="form-row">
            <div class="field"><label for="inv-name">Имя</label><input id="inv-name" v-model="form.name" maxlength="255" required /></div>
            <div class="field"><label for="inv-object-type">Тип</label><select id="inv-object-type" v-model="form.type" :disabled="!!selected"><option v-for="t in types" :key="t" :value="t">{{ t }}</option></select></div>
            <div class="field"><label for="inv-status">Статус</label><select id="inv-status" v-model="form.status"><option v-for="s in statuses" :key="s" :value="s">{{ s }}</option></select></div>
            <div class="field"><label for="inv-os">ОС</label><select id="inv-os" v-model="form.os_family"><option value="">Не указана</option><option v-for="s in systems" :key="s" :value="s">{{ s }}</option></select></div>
            <div class="field"><label for="inv-ips">IP-адреса (через запятую)</label><input id="inv-ips" v-model="form.ips" placeholder="192.0.2.10, 2001:db8::10" /></div>
            <div class="field"><label for="inv-tags">Теги (через запятую)</label><input id="inv-tags" v-model="form.tags" placeholder="production,critical" /></div>
            <div class="field"><label for="inv-owner">UUID владельца (необязательно)</label><input id="inv-owner" v-model="form.owner_user_id" placeholder="UUID участника пространства" /></div>
          </div>
          <div class="field"><label for="inv-attributes">Атрибуты JSON (без секретов)</label><textarea id="inv-attributes" v-model="form.attributes" rows="5" spellcheck="false" /></div>
          <div class="inline-actions"><button class="btn primary" type="submit" :disabled="busy || (!!selected && !canUpdate)">Сохранить</button><button class="btn" type="button" :disabled="busy" @click="toForm()">Сбросить</button></div>
        </form>
      </section>
    </template>
  </div>
</template>
