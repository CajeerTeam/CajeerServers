<script setup lang="ts">
import { inventoryAssetUrl, inventoryContextUrl, selectWorkspace } from '~/composables/inventoryNavigation'
const route=useRoute()
const auth = useAuth()
const api = useApi()
type Org = { id: string; slug: string; name: string }
type Space = { id: string; organization_id: string; slug: string; name: string; role: 'owner'|'manager'|'viewer' }
type Member = { user_id: string; name: string; email: string; role: 'owner'|'manager'|'viewer'; is_active: boolean }
type Asset = { id: string; name: string; type: string; status: string; owner_user_id: string|null }
type User = { id: string; name: string; email: string; is_active: boolean }
const organizations = ref<Org[]>([]), spaces = ref<Space[]>([]), members = ref<Member[]>([]), assets = ref<Asset[]>([]), users = ref<User[]>([])
const selected = ref(''), busy = ref(false), error = ref(''), success = ref('')
const orgName = ref(''), orgSlug = ref(''), spaceName = ref(''), spaceSlug = ref(''), spaceOrganization = ref('')
const targetUser = ref(''), targetRole = ref<'owner'|'manager'|'viewer'>('viewer')
const selectedSpace = computed(() => spaces.value.find(s => s.id === selected.value))
const canView = computed(() => auth.can('workspaces.read'))
const canManageSpace = computed(() => auth.can('workspaces.manage') && ['owner','manager'].includes(selectedSpace.value?.role || ''))
const canManageMembers = computed(() => auth.can('workspaces.members.manage') && selectedSpace.value?.role === 'owner')
const canOwnAssets = computed(() => auth.can('inventory.ownership.manage') && ['owner','manager'].includes(selectedSpace.value?.role || ''))
const canTransfer = computed(() => auth.can('inventory.ownership.manage') && selectedSpace.value?.role === 'owner')
const siblingSpaces = computed(() => spaces.value.filter(s => s.organization_id === selectedSpace.value?.organization_id && s.id !== selected.value))
async function load() {
  if (!canView.value) return
  busy.value = true; error.value = ''
  try {
    const [orgs, workspaces] = await Promise.all([
      api.get<{data:Org[]}>('/organizations'), api.get<{data:Space[]}>('/workspaces')
    ])
    organizations.value = orgs.data; spaces.value = workspaces.data
    selected.value=selectWorkspace(spaces.value,route.query.workspace_id||selected.value)
    if (!spaceOrganization.value) spaceOrganization.value = organizations.value[0]?.id || ''
    if (auth.can('security.users.read')) users.value = (await api.get<{data:User[]}>('/security/users?limit=100')).data
    await loadSpace()
  } catch (e) { error.value = apiError(e).message } finally { busy.value = false }
}
async function loadSpace() {
  if (!selected.value) { members.value = []; assets.value = []; return }
  const id = selected.value
  const [m,a] = await Promise.all([
    api.get<{data:Member[]}>(`/workspaces/${id}/members`),
    api.get<{data:Asset[]}>(`/workspaces/${id}/assets?limit=100`)
  ])
  if (selected.value === id) { members.value = m.data; assets.value = a.data }
}
async function choose(id: string) {
  selected.value = id; error.value = ''; busy.value = true
  void navigateTo(inventoryContextUrl('workspaces',id),{replace:true})
  try { await loadSpace() } catch (e) { error.value = apiError(e).message } finally { busy.value = false }
}
async function mutate(action: () => Promise<unknown>, message: string) {
  busy.value = true; error.value = ''; success.value = ''
  try { await action(); success.value = message; await load() }
  catch (e) { error.value = apiError(e).message } finally { busy.value = false }
}
async function addOrg() {
  await mutate(async () => {
    const result = await api.write<{id:string}>('POST','/organizations',{ slug:orgSlug.value.trim(), name:orgName.value.trim() })
    orgSlug.value = ''; orgName.value = ''
    // New workspace becomes visible after reloading.
    spaceOrganization.value = result.id
  }, 'Организация и её пространство созданы.')
}
async function addWorkspace() {
  await mutate(async () => {
    await api.write('POST','/workspaces',{ organization_id:spaceOrganization.value,slug:spaceSlug.value.trim(),name:spaceName.value.trim() })
    spaceSlug.value = ''; spaceName.value = ''
  }, 'Пространство создано.')
}
async function setMember() {
  if (!selected.value || !targetUser.value) return
  await mutate(async () => {
    await api.write('PUT',`/workspaces/${selected.value}/members/${targetUser.value}`,{role:targetRole.value})
  }, 'Участник назначен.')
}
async function removeMember(m: Member) {
  if (!confirm(`Исключить ${m.email} из пространства?`)) return
  await mutate(() => api.write('DELETE',`/workspaces/${selected.value}/members/${m.user_id}`),'Участник удалён.')
}
async function assignOwner(asset: Asset, event: Event) {
  const owner = (event.target as HTMLSelectElement).value || null
  await mutate(() => api.write('PATCH',`/workspaces/${selected.value}/assets/${asset.id}/owner`,{owner_user_id:owner}),'Владелец объекта обновлён.')
}
async function transfer(asset: Asset, event: Event) {
  const destination = (event.target as HTMLSelectElement).value
  if (!destination || !confirm(`Переместить ${asset.name} в другое пространство?`)) { await loadSpace(); return }
  await mutate(() => api.write('POST',`/workspaces/${selected.value}/assets/${asset.id}/transfer`,{destination_workspace_id:destination}),'Объект перемещён.')
}
onMounted(() => { void load() })
</script>
<template>
  <div>
    <h1>Организации и рабочие пространства</h1>
    <p class="page-subtitle">Изоляция объектов CMDB, управление участниками и назначение владельцев. Устройства здесь не управляются.</p>
    <UiAlert v-if="!canView" message="Недостаточно прав для просмотра пространств." tone="info" />
    <template v-else>
      <UiAlert :message="error" /><UiAlert :message="success" tone="success" />
      <section v-if="auth.can('organizations.manage')" class="panel">
        <h2>Новая организация</h2>
        <form class="form-row" @submit.prevent="addOrg">
          <div class="field"><label for="org-slug">Slug</label><input id="org-slug" v-model="orgSlug" pattern="[a-z][a-z0-9-]{2,63}" minlength="3" maxlength="64" required /></div>
          <div class="field"><label for="org-name">Название</label><input id="org-name" v-model="orgName" maxlength="255" required /></div>
          <button class="btn primary" :disabled="busy" type="submit">Создать организацию</button>
        </form>
      </section>
      <section v-if="auth.can('workspaces.manage')" class="panel">
        <h2>Новое пространство</h2>
        <form class="form-row" @submit.prevent="addWorkspace">
          <div class="field"><label for="ws-org">Организация</label><select id="ws-org" v-model="spaceOrganization" required><option v-for="o in organizations" :key="o.id" :value="o.id">{{ o.name }}</option></select></div>
          <div class="field"><label for="ws-slug">Slug</label><input id="ws-slug" v-model="spaceSlug" pattern="[a-z][a-z0-9-]{2,63}" maxlength="64" required /></div>
          <div class="field"><label for="ws-name">Название</label><input id="ws-name" v-model="spaceName" maxlength="255" required /></div>
          <button class="btn primary" :disabled="busy" type="submit">Создать</button>
        </form>
      </section>
      <section class="panel">
        <div class="toolbar"><h2>Доступные пространства</h2><button class="btn" :disabled="busy" @click="load">Обновить</button></div>
        <div class="inline-actions"><button v-for="s in spaces" :key="s.id" type="button" class="btn" :aria-pressed="selected===s.id" :disabled="busy" @click="choose(s.id)">{{ s.name }} ({{ s.role }})</button></div>
        <div v-if="!spaces.length" class="empty">Нет доступных пространств.</div>
      </section>
      <template v-if="selectedSpace">
        <section class="panel"><h2>Участники: {{ selectedSpace.name }}</h2>
          <form v-if="canManageMembers" class="form-row" @submit.prevent="setMember">
            <div class="field"><label for="member-user">Пользователь</label>
              <select v-if="users.length" id="member-user" v-model="targetUser" required><option value="">Выберите пользователя</option><option v-for="u in users.filter(u=>u.is_active)" :key="u.id" :value="u.id">{{ u.name }} — {{ u.email }}</option></select>
              <input v-else id="member-user" v-model="targetUser" placeholder="UUID пользователя" required />
            </div>
            <div class="field"><label for="member-role">Роль в пространстве</label><select id="member-role" v-model="targetRole"><option value="viewer">viewer</option><option value="manager">manager</option><option value="owner">owner</option></select></div>
            <button class="btn primary" :disabled="busy" type="submit">Назначить</button>
          </form>
          <div class="table-wrap"><table class="table"><thead><tr><th>Пользователь</th><th>Роль</th><th>Действие</th></tr></thead><tbody>
            <tr v-for="m in members" :key="m.user_id"><td>{{ m.name }} <span class="muted">{{ m.email }}</span></td><td>{{ m.role }}</td><td><button v-if="canManageMembers" class="btn" :disabled="busy" @click="removeMember(m)">Исключить</button></td></tr>
          </tbody></table></div>
        </section>
        <section class="panel"><h2>Инфраструктурные объекты (только чтение)</h2>
          <div v-if="!assets.length" class="empty">В этом пространстве ещё нет объектов.</div>
          <div v-else class="table-wrap"><table class="table"><thead><tr><th>Объект</th><th>Статус</th><th>Владелец</th><th>Перемещение</th></tr></thead><tbody>
            <tr v-for="a in assets" :key="a.id"><td><NuxtLink :to="inventoryAssetUrl(a.id,selected)">{{a.name}}</NuxtLink> <span class="muted">{{ a.type }}</span></td><td>{{ a.status }}</td>
              <td><select v-if="canOwnAssets" :value="a.owner_user_id || ''" :disabled="busy" :aria-label="`Владелец ${a.name}`" @change="assignOwner(a,$event)"><option value="">Не назначен</option><option v-for="m in members.filter(m=>m.is_active)" :key="m.user_id" :value="m.user_id">{{ m.name }}</option></select><span v-else>{{ members.find(m=>m.user_id===a.owner_user_id)?.name || 'Не назначен' }}</span></td>
              <td><select v-if="canTransfer && siblingSpaces.length" :disabled="busy" :aria-label="`Переместить ${a.name}`" value="" @change="transfer(a,$event)"><option value="">Переместить…</option><option v-for="s in siblingSpaces.filter(s=>['owner','manager'].includes(s.role))" :key="s.id" :value="s.id">{{ s.name }}</option></select></td>
            </tr>
          </tbody></table></div>
        </section>
      </template>
    </template>
  </div>
</template>
