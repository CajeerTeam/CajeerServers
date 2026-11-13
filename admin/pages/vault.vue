<script setup lang="ts">
import { inventoryContextUrl, selectWorkspace } from '~/composables/inventoryNavigation'
import { clearVaultDraft, vaultFieldIsSensitive, vaultFieldOptions, vaultSecretPayload,
  type VaultCredential, type VaultProfile, type VaultVersion } from '~/composables/vaultProfiles'

type Workspace = { id: string; name: string; role: 'owner' | 'manager' | 'viewer' }
const auth = useAuth()
const api = useApi()
const route = useRoute()
const spaces = ref<Workspace[]>([])
const workspace = ref('')
const records = ref<VaultCredential[]>([])
const profiles = ref<VaultProfile[]>([])
const total = ref(0)
const offset = ref(0)
const pageSize = 50
const selected = ref<VaultCredential | null>(null)
const versions = ref<VaultVersion[]>([])
const busy = ref(false)
const error = ref('')
const notice = ref('')
const newName = ref('')
const newDescription = ref('')
const newType = ref('')
const createScalar = ref('')
const createFields = reactive<Record<string, string>>({})
const editName = ref('')
const editDescription = ref('')
const editStatus = ref<'active' | 'disabled'>('active')
const secretMode = ref<'replace' | 'stage'>('stage')
const operationScalar = ref('')
const operationFields = reactive<Record<string, string>>({})
const confirmation = ref(false)
const selectedWorkspace = computed(() => spaces.value.find(s => s.id === workspace.value))
const isManager = computed(() => ['owner', 'manager'].includes(selectedWorkspace.value?.role || ''))
const canRead = computed(() => auth.can('vault.credentials.read'))
const canCreate = computed(() => isManager.value && auth.can('vault.credentials.create'))
const canUpdate = computed(() => isManager.value && auth.can('vault.credentials.update'))
const canDelete = computed(() => isManager.value && auth.can('vault.credentials.delete'))
const createProfile = computed(() => profiles.value.find(p => p.type === newType.value))
const activeProfile = computed(() => profiles.value.find(p => p.type === selected.value?.type))
const pending = computed(() => versions.value.find(v => v.state === 'pending'))
const hasPrevious = computed(() => offset.value > 0)
const hasNext = computed(() => offset.value + records.value.length < total.value)

function resetMessages() { error.value = ''; notice.value = '' }
function dropOperationSecret() { operationScalar.value = ''; clearVaultDraft(operationFields); confirmation.value = false }
function dropCreateSecret() { createScalar.value = ''; clearVaultDraft(createFields) }
function dropSelection() {
  selected.value = null
  versions.value = []
  editName.value = ''
  editDescription.value = ''
  editStatus.value = 'active'
  dropOperationSecret()
}
function editFrom(item: VaultCredential) {
  selected.value = item
  editName.value = item.name
  editDescription.value = item.description
  editStatus.value = item.state === 'disabled' ? 'disabled' : 'active'
}
function operationError(e: unknown) {
  error.value = apiError(e).message
}
async function loadPage() {
  if (!workspace.value || !canRead.value) { records.value = []; total.value = 0; return }
  const ws = workspace.value
  const result = await api.get<{ data: VaultCredential[]; pagination: { total: number } }>(
    `/vault/credentials?workspace_id=${encodeURIComponent(ws)}&limit=${pageSize}&offset=${offset.value}`)
  if (ws !== workspace.value) return
  records.value = result.data
  total.value = result.pagination.total
}
async function openCredential(id: string) {
  if (!canRead.value || !workspace.value || busy.value) return
  resetMessages()
  dropSelection()
  busy.value = true
  try {
    const ws = workspace.value
    const [detail, history] = await Promise.all([
      api.get<{ data: VaultCredential }>(`/vault/credentials/${id}`),
      api.get<{ data: VaultVersion[] }>(`/vault/credentials/${id}/versions`),
    ])
    if (ws !== workspace.value || detail.data.workspace_id !== ws) return
    editFrom(detail.data)
    versions.value = history.data
  } catch (e) { operationError(e) }
  finally { busy.value = false }
}
async function init() {
  if (!canRead.value) return
  busy.value = true
  resetMessages()
  try {
    const [list, descriptors] = await Promise.all([
      api.get<{ data: Workspace[] }>('/workspaces'),
      api.get<{ data: VaultProfile[] }>('/vault/profiles'),
    ])
    spaces.value = list.data
    profiles.value = descriptors.data
    newType.value = profiles.value[0]?.type || ''
    workspace.value = selectWorkspace(spaces.value, route.query.workspace_id)
    await loadPage()
  } catch (e) { operationError(e) }
  finally { busy.value = false }
}
async function changeWorkspace() {
  dropSelection()
  dropCreateSecret()
  resetMessages()
  offset.value = 0
  records.value = []
  total.value = 0
  void navigateTo(inventoryContextUrl('vault', workspace.value), { replace: true })
  busy.value = true
  try { await loadPage() } catch (e) { operationError(e) }
  finally { busy.value = false }
}
async function changePage(next: number) {
  if (busy.value || next < 0 || next >= total.value) return
  dropSelection()
  resetMessages()
  offset.value = next
  busy.value = true
  try { await loadPage() } catch (e) { operationError(e) }
  finally { busy.value = false }
}
async function refreshSelected(id: string) {
  dropOperationSecret()
  // A fresh server ETag is required after every write (never synthesize revisions).
  const [detail, history] = await Promise.all([
    api.get<{ data: VaultCredential }>(`/vault/credentials/${id}`),
    api.get<{ data: VaultVersion[] }>(`/vault/credentials/${id}/versions`),
  ])
  if (detail.data.workspace_id !== workspace.value) { dropSelection(); return }
  editFrom(detail.data)
  versions.value = history.data
  await loadPage()
}
async function runMutation(action: () => Promise<void>, ok: string) {
  if (busy.value) return
  busy.value = true
  resetMessages()
  try { await action(); notice.value = ok }
  catch (e) {
    const status = apiError(e).status
    if (status === 412 && selected.value) {
      const id = selected.value.id
      try { await refreshSelected(id) } catch { dropSelection() }
      error.value = 'Запись уже изменена. Загружена актуальная версия; проверьте данные и повторите действие.'
    } else operationError(e)
  } finally { busy.value = false }
}
async function createCredential() {
  if (!workspace.value || !canCreate.value || !createProfile.value) return
  await runMutation(async () => {
    const secret = vaultSecretPayload(createProfile.value!, createScalar.value, createFields)
    const created = await api.write<{ data: VaultCredential }>('POST', '/vault/credentials', {
      workspace_id: workspace.value, name: newName.value.trim(), description: newDescription.value,
      type: createProfile.value!.type, secret,
    })
    newName.value = ''; newDescription.value = ''
    offset.value = 0
    await loadPage()
    // Re-fetch the item by ID rather than trusting any user-supplied ETag.
    const id = created.data.id
    const [details, history] = await Promise.all([
      api.get<{ data: VaultCredential }>(`/vault/credentials/${id}`),
      api.get<{ data: VaultVersion[] }>(`/vault/credentials/${id}/versions`),
    ])
    if (details.data.workspace_id === workspace.value) { editFrom(details.data); versions.value = history.data }
  }, 'Credential создан. Значение секрета не отображается и не может быть прочитано через интерфейс.')
  dropCreateSecret()
}
async function saveMetadata() {
  const item = selected.value
  if (!item || !canUpdate.value) return
  await runMutation(async () => {
    await api.write('PATCH', `/vault/credentials/${item.id}`, {
      name: editName.value.trim(), description: editDescription.value, status: editStatus.value,
    }, true, { 'If-Match': item.etag })
    await refreshSelected(item.id)
  }, 'Метаданные и состояние сохранены.')
}
async function applySecret() {
  const item = selected.value
  const profile = activeProfile.value
  if (!item || !profile || !canUpdate.value || item.state !== 'active') return
  const mode = secretMode.value
  if (mode === 'replace' && !confirm('Заменить действующий секрет немедленно? Предыдущая версия перестанет использоваться.')) {
    dropOperationSecret()
    return
  }
  await runMutation(async () => {
    const secret = vaultSecretPayload(profile, operationScalar.value, operationFields)
    if (mode === 'stage') {
      await api.write('POST', `/vault/credentials/${item.id}/rotation`, { secret }, true, { 'If-Match': item.etag })
    } else {
      await api.write('PUT', `/vault/credentials/${item.id}/secret`, { secret }, true, { 'If-Match': item.etag })
    }
    await refreshSelected(item.id)
  }, mode === 'stage' ? 'Новая версия зашифрована и ожидает активации.' : 'Секрет заменён без раскрытия прежнего значения.')
  dropOperationSecret()
}
async function changeRotation(revision: number, action: 'activate' | 'cancel') {
  const item = selected.value
  if (!item || !canUpdate.value || item.state !== 'active') return
  if (action === 'activate' && pending.value?.activation_ready === false) {
    error.value = 'Не все активные коннекторы проверили новую версию. Выполните проверку в разделе «Коннекторы».'
    return
  }
  if (action === 'activate' && !confirmation.value) { error.value = 'Требуется подтверждение внешней проверки новой версии.'; return }
  if (action === 'cancel' && !confirm('Отменить ожидающую версию секрета?')) return
  await runMutation(async () => {
    await api.write('POST', `/vault/credentials/${item.id}/rotation/${revision}/${action}`,
      action === 'activate' ? { confirmed: true } : undefined,
      true, { 'If-Match': item.etag })
    await refreshSelected(item.id)
  }, action === 'activate' ? 'Новая версия активирована.' : 'Ожидающая версия отменена.')
  confirmation.value = false
}
async function archiveCredential() {
  const item = selected.value
  if (!item || !canDelete.value || !confirm(`Архивировать credential «${item.name}»? Привязки Inventory и Connector должны быть сняты.`)) return
  await runMutation(async () => {
    await api.write('DELETE', `/vault/credentials/${item.id}`, undefined, true, { 'If-Match': item.etag })
    dropSelection()
    if (offset.value >= total.value - 1) offset.value = Math.max(0, offset.value - pageSize)
    await loadPage()
  }, 'Credential архивирован. Его использование запрещено.')
}
async function reload() {
  if (busy.value || !workspace.value) return
  const id = selected.value?.id
  busy.value = true
  resetMessages()
  try { if (id) await refreshSelected(id); else await loadPage() }
  catch (e) { operationError(e) }
  finally { busy.value = false }
}
watch(newType, () => dropCreateSecret())
watch(secretMode, () => dropOperationSecret())
onBeforeUnmount(() => { dropCreateSecret(); dropOperationSecret() })
onMounted(() => { void init() })
</script>

<template>
  <div>
    <h1>Credentials Vault</h1>
    <p class="page-subtitle">Зашифрованные учётные данные: создание, отключение, ротация и архивирование. Значения доступны только для записи; прочитать существующий секрет из UI невозможно.</p>
    <UiAlert v-if="!canRead" message="Нет права vault.credentials.read." tone="info" />
    <template v-else>
      <UiAlert :message="error" />
      <UiAlert :message="notice" tone="success" />
      <section class="panel">
        <div class="toolbar"><h2>Workspace</h2><button type="button" class="btn" :disabled="busy || !workspace" @click="reload">Обновить</button></div>
        <label for="vault-workspace">Рабочее пространство</label>
        <select id="vault-workspace" v-model="workspace" :disabled="busy" @change="changeWorkspace">
          <option v-for="space in spaces" :key="space.id" :value="space.id">{{ space.name }} ({{ space.role }})</option>
        </select>
        <p v-if="!spaces.length" class="muted">Нет доступных рабочих пространств.</p>
      </section>
      <section v-if="workspace && canCreate" class="panel" data-testid="vault-create">
        <h2>Новый credential</h2>
        <form autocomplete="off" @submit.prevent="createCredential">
          <div class="form-row">
            <div class="field"><label for="vault-create-name">Название</label><input id="vault-create-name" v-model="newName" required maxlength="255" autocomplete="off" /></div>
            <div class="field"><label for="vault-create-type">Тип</label>
              <select id="vault-create-type" v-model="newType" required>
                <option v-for="profile in profiles" :key="profile.type" :value="profile.type">{{ profile.type }}</option>
              </select>
            </div>
          </div>
          <div class="field"><label for="vault-create-description">Описание</label><textarea id="vault-create-description" v-model="newDescription" maxlength="1024" rows="2" /></div>
          <p v-if="createProfile" class="muted">{{ createProfile.description }}</p>
          <template v-if="createProfile?.format === 'string'">
            <div class="field"><label for="vault-create-secret">Секрет (только запись)</label>
              <input id="vault-create-secret" v-model="createScalar" required type="password" maxlength="65536" autocomplete="new-password" spellcheck="false" />
            </div>
          </template>
          <template v-else-if="createProfile">
            <div class="form-row">
              <div v-for="field in [...createProfile.required_fields, ...createProfile.optional_fields]" :key="field" class="field">
                <label :for="`vault-create-${field}`">{{ field }}{{ createProfile.required_fields.includes(field) ? ' *' : ' (необязательно)' }}</label>
                <select v-if="vaultFieldOptions(field).length" :id="`vault-create-${field}`" v-model="createFields[field]" :required="createProfile.required_fields.includes(field)">
                  <option value="">Выберите значение</option><option v-for="opt in vaultFieldOptions(field)" :key="opt" :value="opt">{{ opt }}</option>
                </select>
                <textarea v-else-if="field === 'private_key'" :id="`vault-create-${field}`" v-model="createFields[field]" rows="7" :required="createProfile.required_fields.includes(field)" autocomplete="off" spellcheck="false" />
                <input v-else :id="`vault-create-${field}`" v-model="createFields[field]" :placeholder="field === 'expires_at' ? 'YYYY-MM-DDTHH:MM:SSZ' : undefined" :type="vaultFieldIsSensitive(field) ? 'password' : 'text'" :required="createProfile.required_fields.includes(field)" autocomplete="off" spellcheck="false" />
              </div>
            </div>
          </template>
          <button class="btn primary" :disabled="busy || !createProfile || !newName.trim()" type="submit">Зашифровать и создать</button>
        </form>
      </section>
      <section v-if="workspace" class="panel" data-testid="vault-list">
        <h2>Учётные данные</h2>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Название</th><th>Тип</th><th>Состояние</th><th>Версия секрета</th><th>Действие</th></tr></thead>
          <tbody><tr v-for="record in records" :key="record.id">
            <td>{{ record.name }}</td><td>{{ record.type }}</td><td>{{ record.state }}</td><td>{{ record.secret_version }}</td>
            <td><button type="button" class="btn" :disabled="busy" @click="openCredential(record.id)">Управлять</button></td>
          </tr></tbody>
        </table></div>
        <p v-if="!records.length" class="empty">Credentials не найдены.</p>
        <div class="toolbar"><span class="muted">Всего: {{ total }} · Показано: {{ records.length ? offset + 1 : 0 }}–{{ offset + records.length }}</span>
          <div class="inline-actions"><button type="button" class="btn" :disabled="busy || !hasPrevious" @click="changePage(Math.max(0, offset - pageSize))">Назад</button>
            <button type="button" class="btn" :disabled="busy || !hasNext" @click="changePage(offset + pageSize)">Вперёд</button></div>
        </div>
      </section>
      <section v-if="selected" class="panel" data-testid="vault-details">
        <h2>Credential: {{ selected.name }}</h2>
        <p class="muted">UUID: <span class="mono">{{ selected.id }}</span> · Тип: {{ selected.type }} · Версия записи: {{ selected.version }}</p>
        <p>Состояние: <span class="tag" :class="selected.state === 'active' ? 'good' : 'bad'">{{ selected.state }}</span></p>
        <form v-if="canUpdate" autocomplete="off" @submit.prevent="saveMetadata">
          <h3>Метаданные и доступность</h3>
          <div class="form-row">
            <div class="field"><label for="vault-edit-name">Название</label><input id="vault-edit-name" v-model="editName" required maxlength="255" /></div>
            <div class="field"><label for="vault-edit-state">Состояние</label>
              <select id="vault-edit-state" v-model="editStatus"><option value="active">active</option><option value="disabled">disabled</option></select></div>
          </div>
          <div class="field"><label for="vault-edit-description">Описание</label><textarea id="vault-edit-description" v-model="editDescription" maxlength="1024" rows="2" /></div>
          <button type="submit" class="btn primary" :disabled="busy">Сохранить метаданные</button>
        </form>
        <section class="section-gap">
          <h3>Версии секрета</h3>
          <p class="muted">Здесь отображаются только номера, состояния и даты. Расшифровка и скачивание отсутствуют.</p>
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Номер</th><th>Состояние</th><th>Создана</th><th>Активирована</th></tr></thead>
            <tbody><tr v-for="version in versions" :key="version.secret_version">
              <td>{{ version.secret_version }}</td><td>{{ version.state }}</td><td>{{ version.created_at }}</td><td>{{ version.activated_at || '—' }}</td>
            </tr></tbody>
          </table></div>
        </section>
        <section v-if="canUpdate && selected.state === 'active'" class="section-gap">
          <h3>Обновление секрета</h3>
          <p class="muted">Рекомендуется сначала подготовить новую версию, проверить доступ к целевой системе и затем явно активировать её. Прямая замена вступает в силу немедленно.</p>
          <form autocomplete="off" @submit.prevent="applySecret">
            <div class="field"><label for="vault-secret-mode">Операция</label>
              <select id="vault-secret-mode" v-model="secretMode" :disabled="busy"><option value="stage">Подготовить ротацию</option><option value="replace">Немедленно заменить</option></select>
            </div>
            <template v-if="activeProfile?.format === 'string'">
              <div class="field"><label for="vault-operation-secret">Новое значение секрета</label>
                <input id="vault-operation-secret" v-model="operationScalar" type="password" required maxlength="65536" autocomplete="new-password" spellcheck="false" />
              </div>
            </template>
            <template v-else-if="activeProfile">
              <div class="form-row">
                <div v-for="field in [...activeProfile.required_fields, ...activeProfile.optional_fields]" :key="field" class="field">
                  <label :for="`vault-operation-${field}`">{{ field }}{{ activeProfile.required_fields.includes(field) ? ' *' : ' (необязательно)' }}</label>
                  <select v-if="vaultFieldOptions(field).length" :id="`vault-operation-${field}`" v-model="operationFields[field]" :required="activeProfile.required_fields.includes(field)">
                    <option value="">Выберите значение</option><option v-for="opt in vaultFieldOptions(field)" :key="opt" :value="opt">{{ opt }}</option>
                  </select>
                  <textarea v-else-if="field === 'private_key'" :id="`vault-operation-${field}`" v-model="operationFields[field]" rows="7" :required="activeProfile.required_fields.includes(field)" autocomplete="off" spellcheck="false" />
                  <input v-else :id="`vault-operation-${field}`" v-model="operationFields[field]" :placeholder="field === 'expires_at' ? 'YYYY-MM-DDTHH:MM:SSZ' : undefined" :type="vaultFieldIsSensitive(field) ? 'password' : 'text'" :required="activeProfile.required_fields.includes(field)" autocomplete="off" spellcheck="false" />
                </div>
              </div>
            </template>
            <button type="submit" class="btn" :disabled="busy || !!pending || !activeProfile">
              {{ secretMode === 'stage' ? 'Подготовить новую версию' : 'Заменить секрет' }}
            </button>
            <p v-if="pending" class="muted">Сначала активируйте или отмените текущую ожидающую версию.</p>
          </form>
        </section>
        <section v-if="canUpdate && pending && selected.state === 'active'" class="section-gap" data-testid="vault-pending">
          <h3>Ожидающая версия №{{ pending.secret_version }}</h3>
          <p v-if="pending.required_targets" class="muted" data-testid="vault-validation-progress">
            Проверено активных коннекторов: {{ pending.validated_targets || 0 }} из {{ pending.required_targets }}.
            Проверки действительны 10 минут и сбрасываются при изменении credential или привязки.
            <NuxtLink :to="inventoryContextUrl('connectors', workspace)">Проверить новую версию через зарегистрированный драйвер</NuxtLink>.
          </p>
          <p v-else class="muted">Активных привязанных коннекторов нет. Проверьте новый секрет вне панели до ручного подтверждения.</p>
          <label class="vault-checkbox"><input v-model="confirmation" type="checkbox" /> Подтверждаю активацию новой версии.</label>
          <div class="inline-actions">
            <button type="button" class="btn primary" :disabled="busy || !confirmation || pending.activation_ready === false" @click="changeRotation(pending.secret_version, 'activate')">Активировать версию</button>
            <button type="button" class="btn" :disabled="busy" @click="changeRotation(pending.secret_version, 'cancel')">Отменить ротацию</button>
          </div>
        </section>
        <section v-if="canDelete" class="section-gap">
          <h3>Архивирование</h3>
          <p class="muted">Перед архивированием отсоедините credential от Inventory и Connector. Операция не удаляет историю аудита.</p>
          <p><NuxtLink :to="inventoryContextUrl('connectors', workspace)">Привязки Connector</NuxtLink> · <NuxtLink :to="inventoryContextUrl('transfer', workspace)">Привязки Inventory</NuxtLink></p>
          <button type="button" class="btn danger" :disabled="busy" @click="archiveCredential">Архивировать credential</button>
        </section>
      </section>
    </template>
  </div>
</template>

<style scoped>
.vault-checkbox { display:flex; align-items:flex-start; gap:10px; margin:12px 0; }
.vault-checkbox input { width:auto; margin-top:4px; flex-shrink:0; }
</style>
