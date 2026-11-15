<script setup lang="ts">
import {osNetworkUrl} from '~/composables/osNetworkNavigation'
const route=useRoute()
const auth = useAuth(); const api = useApi()
interface Check { ok: boolean; detail: string; elapsed_ms: number }
interface Ops { checked_at:string; queues:Record<string,{pending:number;running:number;failed:number;expired_leases:number}|null>; scheduler:{due:number;invalid:number;lag_seconds:number;outbox_pending:number;outbox_retrying:number}|null; disk:{free_bytes:number;total_bytes:number;free_ratio:number}|null; backup:{status:string;started_at:number;last_success_at:number;last_offsite_at:number;bytes:number}|null; backup_expected:boolean; offsite_expected:boolean }
function timestamp(value:number) { return value ? new Date(value*1000).toLocaleString() : 'Нет данных' }
interface Overview { operations?:Ops|null; checked_at: string; ready: boolean; checks: Record<string, Check>; queue: { available: boolean; stats?: Record<string, number | string> }; scheduler: { available: boolean; stats?: Record<string, unknown> } }
interface CmdbCheck { name: string; ok: boolean; count: number }
interface CmdbReport { ok: boolean; checked_at: string; totals: Record<string, number>; checks: CmdbCheck[] }
const data = ref<Overview | null>(null), cmdb = ref<CmdbReport | null>(null), cmdbError = ref(''), busy = ref(false), error = ref('')
async function load() {
  busy.value = true; error.value = ''; cmdbError.value = ''
  try { data.value = await api.get<Overview>('/admin/overview') }
  catch (e) { error.value = apiError(e).message }
  try { cmdb.value = await api.get<CmdbReport>('/admin/cmdb-integrity') }
  catch (e) { cmdb.value = null; cmdbError.value = apiError(e).message }
  finally { busy.value = false }
}
onMounted(() => { if (auth.can('admin.dashboard.read')) void load() })
</script>
<template>
  <div><h1>Обзор инфраструктурной платформы</h1><p class="page-subtitle">Реальное состояние ядра CajeerServers и подключённых сервисов.</p>
    <section v-if="auth.can('workspaces.read')&&auth.can('connectors.targets.read')" class="panel"><h2>OS / Network</h2><p>Доступность Linux, Windows, SNMP и RouterOS, актуальность снимков, события и резервные копии.</p><NuxtLink class="btn primary" :to="osNetworkUrl(route.query.workspace_id)">Открыть Dashboard OS / Network</NuxtLink></section>
    <UiAlert v-if="!auth.can('admin.dashboard.read')" tone="info" message="Недостаточно прав на просмотр системной панели." />
    <template v-else>
      <div class="toolbar"><span class="muted">{{ data?.checked_at ? `Проверено: ${data.checked_at}` : 'Состояние не загружено' }}</span><button class="btn" :disabled="busy" @click="load">{{ busy ? 'Проверка…' : 'Обновить' }}</button></div>
      <UiAlert :message="error" /><div v-if="data" class="grid">
        <div class="panel"><span class="muted">Готовность</span><div class="stat-value">{{ data.ready ? 'Готово' : 'Недоступно' }}</div><span :class="['tag', data.ready ? 'good' : 'bad']">Readiness</span></div>
        <div class="panel"><span class="muted">Очередь</span><div class="stat-value">{{ data.queue.available ? (data.queue.stats?.ready ?? data.queue.stats?.queued ?? 0) : '—' }}</div><span class="muted">Ожидающие задачи</span></div>
        <div class="panel"><span class="muted">Планировщик</span><div class="stat-value">{{ data.scheduler.available ? 'Подключён' : 'Недоступен' }}</div><span class="muted">PostgreSQL scheduler</span></div>
      </div>
      <section v-if="data?.operations" class="panel">
        <h2>Эксплуатация и резервное копирование</h2>
        <div class="table-wrap"><table class="table"><thead><tr><th>Очередь</th><th>Ожидают</th><th>Выполняются</th><th>Ошибки</th><th>Истёкшие lease</th></tr></thead><tbody>
          <tr v-for="(queue,name) in data.operations.queues" :key="name"><td>{{ name }}</td><td>{{ queue?.pending ?? '—' }}</td><td>{{ queue?.running ?? '—' }}</td><td>{{ queue?.failed ?? '—' }}</td><td>{{ queue?.expired_leases ?? '—' }}</td></tr>
        </tbody></table></div>
        <p v-if="data.operations.scheduler">Scheduler: {{ data.operations.scheduler.due }} ожидают, задержка {{ data.operations.scheduler.lag_seconds }} с; outbox {{ data.operations.scheduler.outbox_pending }} / retry {{ data.operations.scheduler.outbox_retrying }}; некорректных {{ data.operations.scheduler.invalid }}.</p>
        <p v-else>Scheduler: недоступен.</p>
        <p v-if="data.operations.disk">Локальное хранилище: свободно {{ (data.operations.disk.free_bytes/1073741824).toFixed(2) }} ГиБ ({{ (100*data.operations.disk.free_ratio).toFixed(1) }}%).</p>
        <p v-else>Данные о диске недоступны.</p>
        <template v-if="data.operations.backup"><p>Managed backup: <span :class="['tag',data.operations.backup.status==='success'?'good':data.operations.backup.status==='failed'?'bad':'']">{{ data.operations.backup.status }}</span> · {{ data.operations.backup_expected ? 'настроен' : 'не настроен через .env' }}</p>
          <p>Последний успешный: {{ timestamp(data.operations.backup.last_success_at) }}. Off-site: {{ timestamp(data.operations.backup.last_offsite_at) }} {{ data.operations.offsite_expected ? '(обязателен)' : '(опционально)' }}.</p>
          <p v-if="data.operations.backup.status==='running'">Начат: {{ timestamp(data.operations.backup.started_at) }}.</p>
        </template><p v-else>Статус backup недоступен.</p>
        <p class="muted">Успешный backup означает проверку архива, но не подтверждает запуск восстановленной системы. Восстановление выполняется только через операторский CLI.</p>
      </section>
      <section class="panel">
        <h2>Целостность Inventory / CMDB</h2>
        <UiAlert :message="cmdbError" />
        <template v-if="cmdb">
          <p><span :class="['tag', cmdb.ok ? 'good' : 'bad']">{{ cmdb.ok ? 'Согласована' : 'Найдены нарушения' }}</span>
            <span class="muted"> Проверено: {{ cmdb.checked_at }}</span></p>
          <div class="grid">
            <div v-for="(count, name) in cmdb.totals" :key="name" class="panel">
              <span class="muted">{{ name }}</span><div class="stat-value">{{ count }}</div>
            </div>
          </div>
          <div class="table-wrap"><table class="table"><thead><tr><th>Проверка</th><th>Нарушений</th><th>Статус</th></tr></thead>
            <tbody><tr v-for="check in cmdb.checks" :key="check.name"><td>{{ check.name }}</td><td>{{ check.count }}</td>
              <td><span :class="['tag', check.ok ? 'good' : 'bad']">{{ check.ok ? 'OK' : 'FAIL' }}</span></td></tr></tbody>
          </table></div>
        </template>
        <p v-else-if="!cmdbError" class="muted">Данные ещё не получены.</p>
      </section>
      <section v-if="data" class="panel"><h2>Проверки зависимостей</h2><div class="table-wrap"><table class="table"><thead><tr><th>Компонент</th><th>Результат</th><th>Время</th></tr></thead><tbody><tr v-for="(check, name) in data.checks" :key="name"><td>{{ name }}</td><td><span :class="['tag', check.ok ? 'good' : 'bad']">{{ check.ok ? 'OK' : check.detail }}</span></td><td>{{ check.elapsed_ms }} мс</td></tr></tbody></table></div></section>
    </template>
  </div>
</template>
