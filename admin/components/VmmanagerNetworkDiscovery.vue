<script setup lang="ts">
import { apiError } from '~/composables/useApi'
import type { VmmanagerNetworkSnapshot as Snapshot, VmmanagerNetworkState as Current } from '../../packages/ts-sdk/src/types'
type Run={id:string;created_at:string;status:string;operation:string;error_code:string|null;result?:{connection?:{vmmanager_network_discovery?:Snapshot}}|null}
const props=defineProps<{targetId:string;profileConfigured:boolean}>()
const api=useApi(),path=`/connectors/targets/${props.targetId}`
const current=ref<Current|null>(null),runs=ref<Run[]>([]),selected=ref<Run|null>(null),offset=ref(0),error=ref(''),loading=ref(false),filter=ref(''),page=ref(0)
const view=ref<'networks'|'pools'|'addresses'|'interfaces'|'issues'>('networks')
let disposed=false,sequence=0,timer:ReturnType<typeof setInterval>|undefined
const snapshot=computed(()=>selected.value?selected.value.result?.connection?.vmmanager_network_discovery||null:current.value?.snapshot||null)
const rows=computed(()=>{const s=snapshot.value;if(!s)return [];const q=filter.value.trim().toLowerCase();return (view.value==='issues'?s.ipam_sync?.issues||[]:s[view.value]).filter(r=>!q||JSON.stringify(r).toLowerCase().includes(q))})
const shown=computed(()=>rows.value.slice(page.value*50,page.value*50+50))
watch([view,filter,selected],()=>page.value=0)
watch(rows,()=>page.value=Math.min(page.value,Math.max(0,Math.ceil(rows.value.length/50)-1)))
async function read<T>(suffix:string){return (await api.get<{data:T}>(path+suffix)).data}
async function refresh(next=offset.value){if(loading.value||disposed)return;const seq=++sequence;loading.value=true;error.value='';try{const [state,history]=await Promise.all([read<Current>('/vmmanager-network'),read<Run[]>(`/runs?collector=vmmanager.network&limit=20&offset=${next}`)]);if(!disposed&&seq===sequence){current.value=state;runs.value=history;offset.value=next}}catch(e){if(!disposed&&seq===sequence){error.value=apiError(e).message;current.value=null;runs.value=[];selected.value=null}}finally{if(!disposed&&seq===sequence)loading.value=false}}
async function history(id:string){if(loading.value||disposed)return;const seq=++sequence;loading.value=true;error.value='';try{const row=await read<Run>('/runs/'+id);if(!disposed&&seq===sequence)selected.value=row}catch(e){if(!disposed&&seq===sequence){error.value=apiError(e).message;selected.value=null}}finally{if(!disposed&&seq===sequence)loading.value=false}}
// Render strongly typed filtered tables; the template keeps each view's row shape distinct.
const networks=computed(()=>view.value==='networks'?shown.value as Snapshot['networks']:[])
const pools=computed(()=>view.value==='pools'?shown.value as Snapshot['pools']:[])
const addresses=computed(()=>view.value==='addresses'?shown.value as Snapshot['addresses']:[])
const interfaces=computed(()=>view.value==='interfaces'?shown.value as Snapshot['interfaces']:[])
const issues=computed(()=>view.value==='issues'?shown.value as NonNullable<Snapshot['ipam_sync']>['issues']:[])
onMounted(()=>{void refresh();timer=setInterval(()=>{if(!document.hidden&&!selected.value)void refresh()},15000)});onBeforeUnmount(()=>{disposed=true;sequence++;if(timer)clearInterval(timer)})
</script>
<template>
  <section class="panel" data-testid="vmmanager-network-discovery">
    <h2>VMmanager: сети и IPAM</h2>
    <p v-if="!profileConfigured" role="alert">Для сбора настройте TLS-профиль VMmanager.</p>
    <p class="muted">План <code>vmmanager.network</code>: Inspect сохраняет наблюдение, Sync импортирует однозначные записи в <NuxtLink to="/ipam">IPAM</NuxtLink>. Сначала синхронизируйте гостей. Пулы и пересекающиеся сети сохраняются в discovery; ручные записи не изменяются.</p>
    <div class="inline-actions"><button class="btn" :disabled="loading" @click="refresh()">Обновить</button><button v-if="selected" class="btn" :disabled="loading" @click="selected=null">Текущий снимок</button></div>
    <p v-if="error" role="alert">{{error}}</p><p v-if="loading" role="status">Загрузка…</p>
    <p v-if="selected" class="muted">Исторический запуск {{selected.created_at}}. Не является текущим состоянием.</p><p v-else-if="current?.state==='stale'" role="alert">Профиль или credentials изменились. Необходим новый сбор.</p><p v-else-if="current?.state==='not_collected'">Снимок ещё не получен.</p>
    <template v-if="snapshot">
      <p v-if="!selected">Наблюдение: {{current?.observed_at}}</p>
      <p>Сети: {{snapshot.counts.networks}} · Пулы: {{snapshot.counts.pools}} · IP: {{snapshot.counts.addresses}} · NIC: {{snapshot.counts.interfaces}}</p>
      <p v-if="!snapshot.complete" role="alert">Ограниченная видимость: запись в IPAM не выполняется.</p>
      <p v-if="snapshot.ipam_sync">Sync: {{snapshot.ipam_sync.applied?'выполнен':'пропущен'}} · создано {{snapshot.ipam_sync.created}} · обновлено {{snapshot.ipam_sync.updated}} · без изменений {{snapshot.ipam_sync.unchanged}} · конфликты {{snapshot.ipam_sync.conflicts}} · пропущено {{snapshot.ipam_sync.skipped}} · ранее импортированные, требующие проверки {{snapshot.ipam_sync.retained_unseen}}</p>
      <p v-if="snapshot.ipam_sync && snapshot.ipam_sync.conflicts+snapshot.ipam_sync.skipped>snapshot.ipam_sync.issues.length" class="muted">Показаны первые 200 диагностических записей; счётчики отражают полный результат.</p>
      <div class="form-row"><label>Раздел<select v-model="view"><option value="networks">Сети</option><option value="pools">Пулы</option><option value="addresses">IP-адреса</option><option value="interfaces">NIC</option><option value="issues">Диагностика Sync</option></select></label><label>Поиск<input v-model="filter" placeholder="CIDR, IP, MAC, ID или статус"></label></div>
      <div class="table-wrap">
        <table v-if="view==='networks'" class="table"><thead><tr><th>CIDR / ID</th><th>Gateway / VLAN</th><th>Userspace / родитель</th><th>Всего / занято / свободно</th><th>Тип / конфликт</th></tr></thead><tbody><tr v-for="n in networks" :key="n.id"><td>{{n.cidr}} (#{{n.id}})</td><td>{{n.gateway??'—'}} / {{n.vlan_id??'—'}}</td><td>{{n.userspace??'—'}} / {{n.parent_id??'—'}}</td><td>{{n.total_ip??'—'}} / {{n.using_ip??'—'}} / {{n.free_ip??'—'}}</td><td>{{n.network_type}} {{n.issue||''}}</td></tr></tbody></table>
        <table v-else-if="view==='pools'" class="table"><thead><tr><th>Пул</th><th>Userspace</th><th>Сети ID</th><th>Всего / занято / свободно</th></tr></thead><tbody><tr v-for="p in pools" :key="p.id"><td>{{p.name}} (#{{p.id}})</td><td>{{p.userspace??'—'}}</td><td>{{p.network_ids?.join(', ')||'—'}}</td><td>{{p.total_ip??'—'}} / {{p.using_ip??'—'}} / {{p.free_ip??'—'}}</td></tr></tbody></table>
        <table v-else-if="view==='addresses'" class="table"><thead><tr><th>IP / Prefix</th><th>Сеть / пул</th><th>Статус</th><th>NIC</th><th>Диагностика</th></tr></thead><tbody><tr v-for="a in addresses" :key="a.id"><td>{{a.address??a.prefix}} (#{{a.id}})</td><td>{{a.network_id??'—'}} / {{a.pool_id??'—'}}</td><td>{{a.status}}<span v-if="a.locked"> · locked</span></td><td>{{a.nic_key??'—'}}</td><td>{{a.issue??'—'}}</td></tr></tbody></table>
        <table v-else-if="view==='interfaces'" class="table"><thead><tr><th>Host / NIC</th><th>Имя / MAC</th><th>Bridge</th><th>IP / конфликт</th></tr></thead><tbody><tr v-for="n in interfaces" :key="n.key"><td>{{n.key}}</td><td>{{n.name??'—'}} / {{n.mac??'—'}}</td><td>{{n.bridge??'—'}}</td><td>{{n.ip_addresses?.join(', ')||'—'}} <span v-if="n.issue">{{n.issue}}</span></td></tr></tbody></table>
        <table v-else class="table"><thead><tr><th>Тип</th><th>Ключ</th><th>Причина</th></tr></thead><tbody><tr v-for="(i,index) in issues" :key="index"><td>{{i.kind}}</td><td>{{i.key}}</td><td>{{i.code}}</td></tr></tbody></table>
      </div>
      <p v-if="!shown.length">Нет подходящих записей.</p><button class="btn" :disabled="page===0" @click="page--">Назад</button> <button class="btn" :disabled="(page+1)*50>=rows.length" @click="page++">Далее</button> <span>Найдено: {{rows.length}}</span>
    </template>
    <h3>История сборов</h3><div class="table-wrap"><table class="table"><thead><tr><th>Время</th><th>Операция / статус</th><th>Снимок</th></tr></thead><tbody><tr v-for="r in runs" :key="r.id"><td>{{r.created_at}}</td><td>{{r.operation}} · {{r.status}} {{r.error_code||''}}</td><td><button class="btn" :disabled="loading||r.status!=='succeeded'" @click="history(r.id)">Открыть</button></td></tr></tbody></table></div><button class="btn" :disabled="loading||offset===0" @click="refresh(offset-20)">Назад</button> <button class="btn" :disabled="loading||runs.length<20||offset>=10000" @click="refresh(offset+20)">Далее</button>
  </section>
</template>
