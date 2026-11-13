<script setup lang="ts">
import { apiError } from '~/composables/useApi'
type Row={id:string;upid:string;node:string;type:string;resource_id:string;user:string;state:string;started_at?:number;ended_at?:number|null;kind?:string;occurred_at?:number|null;observed_at?:string;last_seen_at?:string;check_state?:string|null}
type Page={state:string;observed_at:string|null;summary:{observed_count:number;new_tasks:number;completed_tasks:number;rate_limited:boolean;nodes:Record<string,{active:string;archive:string;head:string;covered_through?:number|null}>}|null;items:Row[];next_before:string|null}
const props=defineProps<{targetId:string;profileConfigured:boolean}>()
const api=useApi(),view=ref<'tasks'|'events'>('tasks'),state=ref(''),page=ref<Page|null>(null),loading=ref(false),error=ref(''),trail=ref<(string|null)[]>([null]);let seq=0,disposed=false,timer:ReturnType<typeof setInterval>|undefined
const labels:Record<string,string>={running:'Выполняется',succeeded:'Успешно',failed:'Ошибка',warning:'Предупреждение',unknown:'Неизвестно',task_discovered:'Обнаружена',task_completed:'Завершена',task_outcome_resolved:'Результат уточнён'}
const date=(v?:number|null)=>v==null?'—':new Date(v*1000).toLocaleString('ru-RU')
async function load(before:string|null=trail.value[trail.value.length-1]??null){const n=++seq;loading.value=true;error.value='';const q=new URLSearchParams({view:view.value,limit:'50'});if(before)q.set('before',before);if(state.value)q.set('state',state.value);try{const r=await api.get<{data:Page}>(`/connectors/targets/${props.targetId}/proxmox-tasks?${q}`);if(!disposed&&n===seq)page.value=r.data}catch(e){if(!disposed&&n===seq){error.value=apiError(e).message;page.value=null}}finally{if(!disposed&&n===seq)loading.value=false}}
function reset(){trail.value=[null];page.value=null;void load(null)}
function next(){const before=page.value?.next_before;if(!before)return;trail.value.push(before);void load(before)}
function prev(){if(trail.value.length>1){trail.value.pop();void load()}}
watch([view,state],reset);onMounted(()=>{void load();timer=setInterval(()=>{if(!document.hidden&&!loading.value&&trail.value.length===1)void load(null)},15000)});onBeforeUnmount(()=>{disposed=true;seq++;if(timer)clearInterval(timer)})
</script>
<template>
<section class="panel" data-testid="proxmox-task-history">
  <h2>Proxmox: история задач и события UPID</h2>
  <p v-if="!profileConfigured" role="alert">Настройте проверенный TLS-профиль.</p>
  <p class="muted">Создайте план <code>proxmox.tasks</code> (Inspect) в «Планы и запуски». История инкрементальная; видимость зависит от ACL и срока хранения PVE. Записи не являются журналом команд или логом задачи.</p>
  <label>Представление <select v-model="view"><option value="tasks">Задачи</option><option value="events">События</option></select></label>
  <label>Состояние <select v-model="state"><option value="">Все</option><option v-for="s in ['running','succeeded','warning','failed','unknown']" :key="s" :value="s">{{ labels[s] }}</option></select></label>
  <button :disabled="loading" @click="load()">Обновить</button>
  <p v-if="error" role="alert">{{error}}</p><p v-if="loading" role="status">Загрузка…</p>
  <p v-if="page?.state==='stale'" role="alert">Область доступа изменилась. Нужен новый сбор.</p><p v-else-if="page?.state==='not_collected'">История ещё не собрана.</p>
  <template v-if="page?.state==='available'">
    <p>Последний сбор: {{page.observed_at}} · обнаружено {{page.summary?.observed_count}} · новых {{page.summary?.new_tasks}} · завершений {{page.summary?.completed_tasks}}</p>
    <p v-if="page.summary?.rate_limited" role="alert">PVE ограничил частоту запросов. Курсор продолжится в следующем запуске.</p>
    <details><summary>Прогресс по узлам</summary><ul><li v-for="(n,node) in page.summary?.nodes" :key="node">{{node}} · архив {{n.archive}} · активные {{n.active}} · новые завершения {{n.head}} · просмотрено до {{date(n.covered_through)}}</li></ul></details>
    <div class="scroll"><table><thead><tr><th>UPID / узел</th><th>Тип / ресурс / пользователь</th><th>Состояние / событие</th><th>Время PVE</th><th>Наблюдение</th></tr></thead><tbody>
      <tr v-for="r in page.items" :key="r.id"><td>{{r.node}}<details><summary>UPID</summary><code>{{r.upid}}</code></details></td><td>{{r.type}} / {{r.resource_id||'—'}} / {{r.user}}</td><td>{{labels[r.state]||r.state}}<span v-if="r.kind"> · {{labels[r.kind]||r.kind}}</span><small v-if="r.check_state&&r.check_state!=='available'"> · проверка: {{r.check_state}}</small></td><td v-if="view==='tasks'">{{date(r.started_at)}} → {{date(r.ended_at)}}</td><td v-else>{{date(r.occurred_at)}}</td><td>{{r.observed_at||r.last_seen_at||'—'}}</td></tr>
    </tbody></table></div><p v-if="!page.items.length">Нет записей по выбранному фильтру.</p>
    <button :disabled="loading||trail.length===1" @click="prev">Назад</button> <button :disabled="loading||!page.next_before" @click="next">Далее</button>
  </template>
</section>
</template>
<style scoped>.scroll{overflow:auto}table{width:100%;border-collapse:collapse}td,th{padding:.5rem;text-align:left;border-bottom:1px solid var(--border,#444);overflow-wrap:anywhere}code{overflow-wrap:anywhere}label,button{margin:.3rem}small{display:block}</style>
