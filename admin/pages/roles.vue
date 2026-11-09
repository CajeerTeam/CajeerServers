<script setup lang="ts">
const auth = useAuth(); const api = useApi()
interface Role { id: string; slug: string; name: string; permissions: string[] }
const knownPermissions = ['security.users.read','security.users.manage','security.roles.read','security.roles.manage','security.roles.grant_administrator','security.audit.read','security.webhooks.manage','admin.dashboard.read','admin.jobs.read','admin.jobs.execute','admin.scheduler.read']
const roles = ref<Role[]>([]), name = ref(''), slug = ref(''), checked = ref<string[]>([]), busy = ref(false), error = ref(''), success = ref('')
async function load() { busy.value = true; error.value = ''; try { roles.value = (await api.get<{ data: Role[] }>('/security/roles')).data } catch (e) { error.value = apiError(e).message } finally { busy.value = false } }
async function create() {
  busy.value = true; error.value = ''; success.value = ''
  try { await api.write('POST', '/security/roles', { name: name.value.trim(), slug: slug.value.trim(), permissions: checked.value }); name.value = ''; slug.value = ''; checked.value = []; success.value = 'Роль создана.'; await load() }
  catch (e) { error.value = apiError(e).message } finally { busy.value = false }
}
onMounted(() => { if (auth.can('security.roles.read')) void load() })
</script>
<template><div><h1>Роли и разрешения</h1><p class="page-subtitle">RBAC с серверной проверкой каждой операции.</p><UiAlert v-if="!auth.can('security.roles.read')" tone="info" message="Недостаточно прав." /><template v-else>
  <UiAlert :message="error" /><UiAlert :message="success" tone="success" />
  <section v-if="auth.can('security.roles.manage')" class="panel"><h2>Создать роль</h2><form @submit.prevent="create"><div class="form-row"><div class="field"><label for="role-name">Название</label><input id="role-name" v-model="name" maxlength="255" required /></div><div class="field"><label for="role-slug">Идентификатор (slug)</label><input id="role-slug" v-model="slug" pattern="[a-z][a-z0-9_-]{2,63}" required /></div></div><p>Разрешения</p><div class="grid"><label v-for="permission in knownPermissions" :key="permission" style="display:flex;gap:8px;align-items:center"><input v-model="checked" type="checkbox" :value="permission" style="width:auto" :disabled="!auth.can(permission)" /><span class="mono small">{{ permission }}</span></label></div><p class="muted small">Можно делегировать только разрешения, которыми обладает текущий администратор.</p><button class="btn primary" :disabled="busy" type="submit">Создать роль</button></form></section>
  <div class="toolbar"><h2>Зарегистрированные роли</h2><button class="btn" :disabled="busy" @click="load">Обновить</button></div><div v-for="role in roles" :key="role.slug" class="panel"><h3>{{ role.name }} <span class="tag">{{ role.slug }}</span></h3><div class="inline-actions"><span v-for="permission in role.permissions" :key="permission" class="tag mono">{{ permission }}</span></div></div><div v-if="!busy && !roles.length" class="empty">Роли не найдены.</div>
</template></div></template>
