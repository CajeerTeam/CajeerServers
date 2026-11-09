<script setup lang="ts">
const auth = useAuth(), api = useApi()
interface Status { ok: boolean; status: string; checks: Record<string, {ok:boolean;detail:string;elapsed_ms:number}>; checked_at: string }
const data = ref<Status | null>(null), busy = ref(false), error = ref('')
async function load() { busy.value = true; error.value = ''; try { data.value = await api.get<Status>('/admin/diagnostics') } catch (e) { error.value = apiError(e).message } finally { busy.value = false } }
onMounted(() => { if (auth.can('admin.dashboard.read')) void load() })
</script>
<template><div><h1>Диагностика системы</h1><p class="page-subtitle">Глубокая проверка PostgreSQL, Redis, Queue, Scheduler и Storage. Для Storage может выполняться запись тестового объекта.</p><UiAlert v-if="!auth.can('admin.dashboard.read')" tone="info" message="Недостаточно прав." /><template v-else><div class="toolbar"><span class="muted">{{ data?.checked_at || 'Не проверено' }}</span><button class="btn" :disabled="busy" @click="load">{{ busy ? 'Диагностика…' : 'Проверить повторно' }}</button></div><UiAlert :message="error" /><div v-if="data" class="panel"><h2>{{ data.ok ? 'Все проверки пройдены' : 'Есть недоступные сервисы' }}</h2><div class="table-wrap"><table class="table"><thead><tr><th>Сервис</th><th>Результат</th><th>Время (мс)</th></tr></thead><tbody><tr v-for="(item,key) in data.checks" :key="key"><td>{{ key }}</td><td><span :class="['tag',item.ok?'good':'bad']">{{ item.ok ? 'OK' : item.detail }}</span></td><td>{{ item.elapsed_ms }}</td></tr></tbody></table></div></div></template></div></template>
