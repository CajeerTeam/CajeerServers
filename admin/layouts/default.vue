<template>
  <main class="shell">
    <aside>
      <h1>CajeerServers</h1>
      <NuxtLink to="/">Обзор</NuxtLink>
      <NuxtLink to="/inventory">Inventory</NuxtLink>
      <NuxtLink to="/connectors">Коннекторы</NuxtLink>
      <NuxtLink to="/sync">Синхронизация</NuxtLink>
      <NuxtLink to="/scripts">Скрипты</NuxtLink>
      <NuxtLink to="/actions">Действия</NuxtLink>
      <NuxtLink to="/system">Система</NuxtLink>
      <NuxtLink to="/settings">Настройки</NuxtLink>
    </aside>
    <section><slot /></section>
  </main>
</template>
<style scoped>
.shell{display:grid;grid-template-columns:260px 1fr;min-height:100vh;font-family:system-ui,sans-serif}aside{padding:24px;background:#111827;color:white;display:flex;flex-direction:column;gap:12px}a{color:inherit;text-decoration:none}section{padding:32px}
</style>
