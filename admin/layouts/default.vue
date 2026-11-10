<script setup lang="ts">
const auth = useAuth()
const links = computed(() => [
  { to: '/', label: 'Обзор', permission: 'admin.dashboard.read' },
  { to: '/workspaces', label: 'Организации и пространства', permission: 'workspaces.read' },
  { to: '/inventory', label: 'Инвентаризация', permission: 'inventory.assets.read' },
  { to: '/catalog', label: 'Теги и группы', permission: 'inventory.catalog.read' },
  { to: '/history', label: 'История CMDB', permission: 'inventory.history.read' },
  { to: '/ipam', label: 'Сети и IPAM', permission: 'inventory.network.read' },
  { to: '/topology', label: 'Связи и топология', permission: 'inventory.relationships.read' },
  { to: '/tasks', label: 'Очередь и задачи', permission: 'admin.jobs.read' },
  { to: '/scheduler', label: 'Планировщик', permission: 'admin.scheduler.read' },
  { to: '/users', label: 'Пользователи', permission: 'security.users.read' },
  { to: '/roles', label: 'Роли', permission: 'security.roles.read' },
  { to: '/audit', label: 'Аудит', permission: 'security.audit.read' },
  { to: '/system', label: 'Диагностика', permission: 'admin.dashboard.read' },
  { to: '/settings', label: 'Профиль и безопасность', permission: '' },
].filter(item => (!item.permission || auth.can(item.permission)) && (item.to !== '/topology' || (auth.can('inventory.assets.read') && auth.can('inventory.network.read')))))
const loggingOut = ref(false)
const error = ref('')
async function logout() {
  loggingOut.value = true; error.value = ''
  try { await auth.signOut(); await navigateTo('/login', { replace: true }) }
  catch (e) { error.value = apiError(e).message }
  finally { loggingOut.value = false }
}
</script>
<template>
  <div v-if="!auth.ready || !auth.user" class="auth-page" aria-live="polite"><span class="progress">Проверка сессии…</span></div>
  <div v-else class="layout">
    <nav class="sidebar" aria-label="Основная навигация">
      <div class="brand">CajeerServers<span>Панель администратора</span></div>
      <NuxtLink v-for="link in links" :key="link.to" class="nav-link" :to="link.to">{{ link.label }}</NuxtLink>
      <div class="sidebar-foot"><p>{{ auth.user.name }}</p><p class="mono">{{ auth.user.email }}</p><UiAlert :message="error" /><button class="btn" type="button" :disabled="loggingOut" @click="logout">Выйти</button></div>
    </nav>
    <main class="main"><slot /></main>
  </div>
</template>
