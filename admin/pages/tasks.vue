<script setup lang="ts">
const auth = useAuth(), api = useApi()
interface Job { id: string; type: string; status: string; attempts: number; max_attempts: number; created_at?: string; finished_at?: string }
interface Jobs { driver: string; stats: Record<string, number | string>; data: Job[] }
const jobs = ref<Jobs | null>(null), type = ref('runtime.diagnostics'), lookupId = ref(''), selected = ref<Job | null>(null)
const busy = ref(false), error = ref(''), success = ref('')
async function load() { busy.value = true; error.value = ''; try { jobs.value = await api.get<Jobs>('/admin/jobs') } catch (e) { error.value = apiError(e).message } finally { busy.value = false } }
async function create() {
  if (!confirm(`Поставить задачу ${type.value} в очередь?`)) return
  busy.value = true; error.value = ''; success.value = ''
  try { const result = await api.write<{id: string;status:string}>('POST', '/admin/jobs', { type: type.value }); lookupId.value = result.id; success.value = `Задача ${result.id} добавлена в очередь.`; await load() }
  catch (e) { error.value = apiError(e).message } finally { busy.value = false }
}
async function lookup(id: string) { busy.value = true; error.value = ''; try { selected.value = (await api.get<{job:Job}>(`/admin/jobs/${encodeURIComponent(id)}`)).job } catch (e) { error.value = apiError(e).message } finally { busy.value = false } }
onMounted(() => { if (auth.can('admin.jobs.read')) void load() })
</script>
<template><div><h1>Очередь и диагностические задачи</h1><p class="page-subtitle">Выполнение происходит в отдельном worker, а не в HTTP-запросе. Разрешены только зарегистрированные диагностические обработчики.</p><UiAlert v-if="!auth.can('admin.jobs.read')" tone="info" message="Недостаточно прав." /><template v-else><UiAlert :message="error" /><UiAlert :message="success" tone="success" />
  <section v-if="auth.can('admin.jobs.execute')" class="panel"><h2>Запустить диагностику</h2><div class="inline-actions"><select v-model="type" aria-label="Тип задачи" style="width:auto"><option value="runtime.diagnostics">Проверка PHP runtime</option><option value="storage.probe">Проверка записи и чтения Storage</option></select><button class="btn primary" :disabled="busy" @click="create">Поставить в очередь</button></div><p class="muted small">Запустите `php bin/cajeer queue:work --max-seconds=3600` как отдельный worker.</p></section>
  <section class="panel"><div class="toolbar"><h2>Статистика очереди</h2><button class="btn" :disabled="busy" @click="load">Обновить</button></div><p v-if="jobs" class="muted">Драйвер: {{ jobs.driver }}</p><div v-if="jobs" class="grid"><div v-for="(value,key) in jobs.stats" :key="key" class="panel"><span class="muted">{{ key }}</span><div class="stat-value">{{ value }}</div></div></div></section>
  <section class="panel"><h2>Проверить задачу по ID</h2><form class="inline-actions" @submit.prevent="lookup(lookupId)"><input v-model="lookupId" aria-label="UUID задачи" placeholder="UUID задачи" pattern="[0-9a-fA-F-]{36}" required style="max-width:420px" /><button class="btn" :disabled="busy" type="submit">Проверить</button></form><div v-if="selected" class="panel"><strong>{{ selected.type }}</strong> <span class="tag">{{ selected.status }}</span><p class="mono small">{{ selected.id }}</p><p>Попыток: {{ selected.attempts }} / {{ selected.max_attempts }}</p></div></section>
  <section class="panel"><h2>Последние задачи</h2><p v-if="jobs?.driver === 'redis'" class="muted small">Redis-драйвер не поддерживает перечисление всех задач. Используйте поиск по UUID.</p><div v-if="jobs?.data.length" class="table-wrap"><table class="table"><thead><tr><th>Тип</th><th>Статус</th><th>Создано</th><th>ID</th></tr></thead><tbody><tr v-for="job in jobs.data" :key="job.id"><td>{{ job.type }}</td><td><span class="tag">{{ job.status }}</span></td><td>{{ job.created_at }}</td><td><button class="btn mono small" @click="lookup(job.id)">{{ job.id }}</button></td></tr></tbody></table></div><div v-else class="empty">Нет записей или драйвер не поддерживает перечисление.</div></section></template></div></template>
