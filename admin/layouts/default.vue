<script setup lang="ts">
import {vmmanagerUrl} from '~/composables/vmmanagerNavigation'
import {proxmoxUrl} from '~/composables/proxmoxNavigation'
import { inventoryContextUrl, queryUuid, type InventorySection } from '~/composables/inventoryNavigation'
const route = useRoute()
const sections: InventorySection[] = ['inventory','catalog','history','transfer','ipam','topology','workspaces','connectors','vault','collections','scripts']
const auth = useAuth()
const {ready:authReady,user:authUser}=auth
const links = computed(() => [
  { to: '/', label: 'Обзор', permission: 'admin.dashboard.read' },
  { to: '/workspaces', label: 'Организации и пространства', permission: 'workspaces.read' },
  { to: '/inventory', label: 'Инвентаризация', permission: 'inventory.assets.read' },
  { to: '/catalog', label: 'Теги и группы', permission: 'inventory.catalog.read' },
  { to: '/history', label: 'История CMDB', permission: 'inventory.history.read' },
  { to: '/transfer', label: 'Импорт / Экспорт', permission: 'inventory.transfer.read' },
  { to: '/ipam', label: 'Сети и IPAM', permission: 'inventory.network.read' },
  { to: '/topology', label: 'Связи и топология', permission: 'inventory.relationships.read' },
  { to: '/vmmanager', label: 'VMmanager', permission: 'connectors.targets.read' },
  { to: '/proxmox', label: 'Proxmox VE / PBS', permission: 'connectors.targets.read' },
  { to: '/connectors', label: 'Конфигурации подключений', permission: 'connectors.targets.read' },
  { to: '/vault', label: 'Хранилище учётных данных', permission: 'vault.credentials.read' },
  { to: '/collections', label: 'Сбор данных', permission: 'connectors.targets.read' },
  { to: '/scripts', label: 'Реестр скриптов', permission: 'scripts.read' },
  { to: '/tasks', label: 'Очередь и задачи', permission: 'admin.jobs.read' },
  { to: '/scheduler', label: 'Планировщик', permission: 'admin.scheduler.read' },
  { to: '/users', label: 'Пользователи', permission: 'security.users.read' },
  { to: '/roles', label: 'Роли', permission: 'security.roles.read' },
  { to: '/audit', label: 'Аудит', permission: 'security.audit.read' },
  { to: '/system', label: 'Диагностика', permission: 'admin.dashboard.read' },
  { to: '/settings', label: 'Профиль и безопасность', permission: '' },
].filter(item => (!item.permission || auth.can(item.permission)) && (item.to !== '/collections' || auth.can('workspaces.read')) && (!['/proxmox','/vmmanager'].includes(item.to) || (auth.can('workspaces.read') && auth.can('inventory.assets.read'))) && (item.to !== '/topology' || (auth.can('inventory.assets.read') && auth.can('inventory.network.read'))))
  .map(item => ({...item, to: item.to==='/vmmanager'?vmmanagerUrl(route.query.workspace_id):item.to==='/proxmox'?proxmoxUrl(route.query.workspace_id):sections.includes(item.to.slice(1) as InventorySection)
    ? inventoryContextUrl(item.to.slice(1) as InventorySection, queryUuid(route.query.workspace_id)) : item.to})))
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
  <div v-if="!authReady || !authUser" class="auth-page" aria-live="polite"><span class="progress">Проверка сессии…</span></div>
  <div v-else class="layout">
    <nav class="sidebar" aria-label="Основная навигация">
      <div class="brand">CajeerServers<span>Панель администратора</span></div>
      <NuxtLink v-for="link in links" :key="link.to" class="nav-link" :to="link.to">{{ link.label }}</NuxtLink>
      <div class="sidebar-foot"><p>{{ authUser?.name }}</p><p class="mono">{{ authUser?.email }}</p><UiAlert :message="error" /><button class="btn" type="button" :disabled="loggingOut" @click="logout">Выйти</button></div>
    </nav>
    <main class="main"><slot /></main>
  </div>
</template>
