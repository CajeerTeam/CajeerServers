<script setup lang="ts">
import { apiError } from '~/composables/useApi'
type Row={id:string;remote_id:string;consul_id:string|null;type:string;resource_id:string|null;state:string;kind?:string;observed_at?:string;first_seen_at?:string;last_seen_at?:string;check_state?:string|null}
type Page={state:string;observed_at:string|null;summary:{observed_count:number;new_tasks:number;completed_tasks:number;high_water_id:number;sweep_after:number;caught_up:boolean;visibility:string}|null;items:Row[];next_before:string|null}
const props=defineProps<{targetId:string;profileConfigured:boolean}>()
const api=useApi(),view=ref<'tasks'|'events'>('tasks'),state=ref(''),page=ref<Page|null>(null),loading=ref(false),error=ref(''),trail=ref<(string|null)[]>([null]);let seq=0,disposed=false,timer:ReturnType<typeof setInterval>|undefined
const labels:Record<string,string>={queued:'Создана',deferred:'Отложена',running:'Выполняется',succeeded:'Успешно',failed:'Ошибка',unknown:'Неизвестно',task_started:'Запущена',task_deferred:'Отложена',task_queued:'Создана',task_discovered:'Обнаружена',task_completed:'Завершена',task_outcome_resolved:'Результат уточнён'}
async function load(before:string|null=trail.value[trail.value.length-1]??null){const n=++seq;loading.value=true;error.value='';const q=new URLSearchParams({view:view.value,limit:'50'});if(before)q.set('before',before);if(state.value)q.set('state',state.value);try{const r=await api.get<{data:Page}>(`/connectors/targets/${props.targetId}/vmmanager-tasks?${q}`);if(!disposed&&n===seq)page.value=r.data}catch(e){if(!disposed&&n===seq){error.value=apiError(e).message;page.value=null}}finally{if(!disposed&&n===seq)loading.value=false}}
function reset(){trail.value=[null];page.value=null;void load(null)}
function next(){const before=page.value?.next_before;if(!before)return;trail.value.push(before);void load(before)}
function prev(){if(trail.value.length>1){trail.value.pop();void load()}}
watch([view,state],reset);onMounted(()=>{void load();timer=setInterval(()=>{if(!document.hidden&&!loading.value&&trail.value.length===1)void load(null)},15000)});onBeforeUnmount(()=>{disposed=true;seq++;if(timer)clearInterval(timer)})
</script>
<template>
<section class="panel" data-testid="vmmanager-task-history">
  <h2>VMmanager: история задач и события</h2>
  <p v-if="!profileConfigured" role="alert">Настройте проверенный TLS-профиль.</p>
  <p class="muted">Создайте план <code>vmmanager.tasks</code> (Inspect) в «Планы и запуски». История инкрементальная; видимость зависит от ACL и срока хранения VMmanager. Записи не являются журналом команд или логом задачи.</p>
  <label>Представление <select v-model="view"><option value="tasks">Задачи</option><option value="events">События</option></select></label>
  <label>Состояние <select v-model="state"><option value="">Все</option><option v-for="s in ['queued','deferred','running','succeeded','failed','unknown']" :key="s" :value="s">{{ labels[s] }}</option></select></label>
  <button :disabled="loading" @click="load()">Обновить</button>
  <p v-if="error" role="alert">{{error}}</p><p v-if="loading" role="status">Загрузка…</p>
  <p v-if="page?.state==='stale'" role="alert">Область доступа изменилась. Нужен новый сбор.</p><p v-else-if="page?.state==='not_collected'">История ещё не собрана.</p>
  <template v-if="page?.state==='available'">
    <p>Последний сбор: {{page.observed_at}} · обнаружено {{page.summary?.observed_count}} · новых {{page.summary?.new_tasks}} · завершений {{page.summary?.completed_tasks}}</p>
    <p v-if="page.summary">Курсор: {{page.summary.high_water_id}} · повторный проход: {{page.summary.sweep_after}} · {{page.summary.caught_up?'Новые страницы просмотрены':'Загрузка продолжается'}} · видимость: {{page.summary.visibility==='administrative'?'Административная':'Ограниченная'}}</p>
    <div class="scroll"><table><thead><tr><th>ID / Consul ID</th><th>Тип / ресурс</th><th>Состояние / событие</th><th>Первое наблюдение</th><th>Последнее наблюдение</th></tr></thead><tbody>
      <tr v-for="r in page.items" :key="r.id"><td>{{r.remote_id}}<small v-if="r.consul_id">Consul: {{r.consul_id}}</small></td><td>{{r.type}} / {{r.resource_id||'—'}}</td><td>{{labels[r.state]||r.state}}<span v-if="r.kind"> · {{labels[r.kind]||r.kind}}</span><small v-if="r.check_state&&r.check_state!=='available'"> · проверка: {{r.check_state}}</small></td><td>{{r.first_seen_at||'—'}}</td><td>{{r.observed_at||r.last_seen_at||'—'}}</td></tr>
    </tbody></table></div><p v-if="!page.items.length">Нет записей по выбранному фильтру.</p>
    <button :disabled="loading||trail.length===1" @click="prev">Назад</button> <button :disabled="loading||!page.next_before" @click="next">Далее</button>
  </template>
</section>
</template>
<style scoped>.scroll{overflow:auto}table{width:100%;border-collapse:collapse}td,th{padding:.5rem;text-align:left;border-bottom:1px solid var(--border,#444);overflow-wrap:anywhere}code{overflow-wrap:anywhere}label,button{margin:.3rem}small{display:block}</style>
