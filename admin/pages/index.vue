<script setup lang="ts">
const auth = useAuth(); const api = useApi()
interface Check { ok: boolean; detail: string; elapsed_ms: number }
interface Overview { checked_at: string; ready: boolean; checks: Record<string, Check>; queue: { available: boolean; stats?: Record<string, number | string> }; scheduler: { available: boolean; stats?: Record<string, unknown> } }
const data = ref<Overview | null>(null), busy = ref(false), error = ref('')
async function load() { busy.value = true; error.value = ''; try { data.value = await api.get<Overview>('/admin/overview') } catch (e) { error.value = apiError(e).message } finally { busy.value = false } }
onMounted(() => { if (auth.can('admin.dashboard.read')) void load() })
</script>
<template>
  <div><h1>Обзор инфраструктурной платформы</h1><p class="page-subtitle">Реальное состояние ядра CajeerServers и подключённых сервисов.</p>
    <UiAlert v-if="!auth.can('admin.dashboard.read')" tone="info" message="Недостаточно прав на просмотр системной панели." />
    <template v-else>
      <div class="toolbar"><span class="muted">{{ data?.checked_at ? `Проверено: ${data.checked_at}` : 'Состояние не загружено' }}</span><button class="btn" :disabled="busy" @click="load">{{ busy ? 'Проверка…' : 'Обновить' }}</button></div>
      <UiAlert :message="error" /><div v-if="data" class="grid">
        <div class="panel"><span class="muted">Готовность</span><div class="stat-value">{{ data.ready ? 'Готово' : 'Недоступно' }}</div><span :class="['tag', data.ready ? 'good' : 'bad']">Readiness</span></div>
        <div class="panel"><span class="muted">Очередь</span><div class="stat-value">{{ data.queue.available ? (data.queue.stats?.ready ?? data.queue.stats?.queued ?? 0) : '—' }}</div><span class="muted">Ожидающие задачи</span></div>
        <div class="panel"><span class="muted">Планировщик</span><div class="stat-value">{{ data.scheduler.available ? 'Подключён' : 'Недоступен' }}</div><span class="muted">PostgreSQL scheduler</span></div>
      </div>
      <section v-if="data" class="panel"><h2>Проверки зависимостей</h2><div class="table-wrap"><table class="table"><thead><tr><th>Компонент</th><th>Результат</th><th>Время</th></tr></thead><tbody><tr v-for="(check, name) in data.checks" :key="name"><td>{{ name }}</td><td><span :class="['tag', check.ok ? 'good' : 'bad']">{{ check.ok ? 'OK' : check.detail }}</span></td><td>{{ check.elapsed_ms }} мс</td></tr></tbody></table></div></section>
    </template>
  </div>
</template>
