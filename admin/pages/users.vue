<script setup lang="ts">
const auth = useAuth(); const api = useApi()
interface User { id: string; email: string; name: string; is_active: boolean; two_factor_enabled: boolean; roles: string[] }
interface Role { slug: string; name: string; permissions: string[] }
const users = ref<User[]>([]), roles = ref<Role[]>([]), busy = ref(false), error = ref(''), success = ref('')
const email = ref(''), name = ref(''), password = ref('')
const canManage = computed(() => auth.can('security.users.manage'))
async function load() {
  busy.value = true; error.value = ''
  try {
    users.value = (await api.get<{ data: User[] }>('/security/users?limit=100')).data
    if (auth.can('security.roles.read')) roles.value = (await api.get<{ data: Role[] }>('/security/roles')).data
  } catch (e) { error.value = apiError(e).message } finally { busy.value = false }
}
async function create() {
  busy.value = true; error.value = ''; success.value = ''
  try {
    await api.write('POST', '/security/users', { email: email.value.trim(), name: name.value.trim(), password: password.value })
    email.value = ''; name.value = ''; password.value = ''; success.value = 'Пользователь создан.'; await load()
  } catch (e) { error.value = apiError(e).message } finally { busy.value = false }
}
async function changeState(user: User) {
  if (!confirm(`Подтвердить ${user.is_active ? 'отключение' : 'включение'} пользователя ${user.email}?`)) return
  busy.value = true; error.value = ''
  try { await api.write('PATCH', `/security/users/${user.id}`, { active: !user.is_active }); await load() }
  catch (e) { error.value = apiError(e).message } finally { busy.value = false }
}
async function membership(user: User, slug: string, grant: boolean) {
  if (slug === 'administrator' && !confirm(`Подтвердить изменение роли administrator для ${user.email}?`)) return
  busy.value = true; error.value = ''
  try { await api.write(grant ? 'POST' : 'DELETE', `/security/users/${user.id}/roles/${slug}`); await load() }
  catch (e) { error.value = apiError(e).message } finally { busy.value = false }
}
onMounted(() => { if (auth.can('security.users.read')) void load() })
</script>
<template>
  <div><h1>Пользователи</h1><p class="page-subtitle">Управление локальными учётными записями и назначенными ролями.</p>
    <UiAlert v-if="!auth.can('security.users.read')" message="Недостаточно прав для просмотра пользователей." tone="info" />
    <template v-else>
      <UiAlert :message="error" /><UiAlert :message="success" tone="success" />
      <section v-if="canManage" class="panel"><h2>Создать пользователя</h2><form @submit.prevent="create"><div class="form-row"><div class="field"><label for="user-email">Email</label><input id="user-email" v-model="email" type="email" required maxlength="255" autocomplete="off" /></div><div class="field"><label for="user-name">Имя</label><input id="user-name" v-model="name" required maxlength="255" /></div><div class="field"><label for="user-password">Пароль (от 14 символов)</label><input id="user-password" v-model="password" type="password" minlength="14" required autocomplete="new-password" /></div></div><button class="btn primary" :disabled="busy">Создать</button></form></section>
      <div class="toolbar"><h2>Учётные записи</h2><button class="btn" :disabled="busy" @click="load">Обновить</button></div>
      <div v-if="users.length" class="panel table-wrap"><table class="table"><thead><tr><th>Пользователь</th><th>Статус</th><th>Роли</th><th>Управление</th></tr></thead><tbody><tr v-for="user in users" :key="user.id"><td><strong>{{ user.name }}</strong><div class="muted small">{{ user.email }}</div><div v-if="user.two_factor_enabled" class="tag good">2FA</div></td><td><span :class="['tag', user.is_active ? 'good' : 'bad']">{{ user.is_active ? 'Активен' : 'Отключён' }}</span></td><td><div class="inline-actions"><span v-for="role in user.roles" :key="role" class="tag">{{ role }}</span></div></td><td><div class="inline-actions"><button v-if="canManage" class="btn" type="button" :disabled="busy || user.id === auth.user?.id" @click="changeState(user)">{{ user.is_active ? 'Отключить' : 'Включить' }}</button><template v-if="auth.can('security.roles.manage')"><button v-for="role in roles" :key="role.slug" type="button" class="btn" :disabled="busy || (role.slug === 'administrator' && !auth.can('security.roles.grant_administrator'))" @click="membership(user,role.slug,!user.roles.includes(role.slug))">{{ user.roles.includes(role.slug) ? '−' : '+' }} {{ role.slug }}</button></template></div></td></tr></tbody></table></div>
      <div v-else-if="!busy" class="empty">Пользователи не найдены.</div>
    </template>
  </div>
</template>
