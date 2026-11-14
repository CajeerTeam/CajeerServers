<script setup lang="ts">
import { apiError } from '~/composables/useApi'
const props=defineProps<{workspaceId:string;canWrite:boolean;initialTargetId?:string}>()
const emit=defineEmits<{(e:'update:mutating',v:boolean):void}>()
const api=useApi()
type Device={id:string;label:string;external_id:string;asset_id:string|null;asset_name:string|null}
type Nic={id:string;device_id:string;name:string;mac:string|null;vlan_id:number|null}
type Net={id:string;cidr:string;vlan_id:number|null;scope:string}
type Address={id:string;interface_id:string|null;network_id:string|null;address:string;issue:string|null}
type Edge={id:string;source:string;target:string;kind:string;evidence:string}
type Projection={complete:boolean;devices:Device[];interfaces:Nic[];networks:Net[];addresses:Address[];links:Edge[];neighbors:Record<string,string|null>[];issues:Record<string,unknown>[]}
type Source={target_id:string;provider:string;name:string;state:string;fresh:boolean;observed_at:string|null;projection:Projection|null}
type Page={sources:Source[];physical_links:Edge[];conflicts:{kind:string;value:string;observations:string[];observation_count:number;code:string}[];conflicts_truncated:boolean;has_more:boolean}
type Report={created:number;unchanged:number;conflicts:number;skipped:number;retained_unseen:number;issues:{kind:string;key:string;code:string}[]}
const page=ref<Page|null>(null),busy=ref(false),error=ref(''),offset=ref(0),limit=ref(5),selected=ref(''),networkId=ref(''),report=ref<Report|null>(null),search=ref(''),rowPage=ref(0)
const source=computed(()=>page.value?.sources.find(s=>s.target_id===selected.value))
const projection=computed(()=>source.value?.projection)
const allNics=computed(()=>new Map(page.value?.sources.flatMap(s=>(s.projection?.interfaces||[]).map(i=>[i.id,`${s.name}: ${i.name}`] as const))||[]))
const devices=computed(()=>new Map(projection.value?.devices.map(d=>[d.id,d.asset_name||d.label])||[]))
const networks=computed(()=>new Map(projection.value?.networks.map(n=>[n.id,n.cidr])||[]))
const interfaces=computed(()=>new Map(projection.value?.interfaces.map(n=>[n.id,n.name])||[]))
const matching=computed(()=>{const q=search.value.toLowerCase();return (projection.value?.interfaces||[]).filter(i=>`${i.name} ${i.mac||''} ${devices.value.get(i.device_id)||''}`.toLowerCase().includes(q))})
const rows=computed(()=>matching.value.slice(rowPage.value*50,(rowPage.value+1)*50))
const addresses=computed(()=>projection.value?.addresses.filter(a=>a.interface_id===null||rows.value.some(i=>i.id===a.interface_id))||[])
const canImport=computed(()=>props.canWrite&&source.value?.fresh&&projection.value?.complete&&!!projection.value?.devices[0]?.asset_id&&['ssh','winrm','routeros'].includes(source.value?.provider||''))
let generation=0
async function refresh(){
  const request=++generation,ws=props.workspaceId;busy.value=true;error.value='';page.value=null;report.value=null
  if(!ws){busy.value=false;return}
  try{const data=(await api.get<{data:Page}>(`/inventory/network-discovery?workspace_id=${encodeURIComponent(ws)}&limit=${limit.value}&offset=${offset.value}`)).data
    if(request!==generation)return;page.value=data;if(!data.sources.some(s=>s.target_id===selected.value))selected.value=props.initialTargetId?(data.sources.some(s=>s.target_id===props.initialTargetId)?props.initialTargetId:''):(data.sources[0]?.target_id||'')
  }catch(e){if(request===generation)error.value=apiError(e).message}finally{if(request===generation)busy.value=false}
}
watch(()=>props.workspaceId,()=>{offset.value=0;selected.value='';networkId.value='';refresh()},{immediate:true})
watch([selected,search],()=>{rowPage.value=0;report.value=null;networkId.value=''})
async function reconcile(){
  const s=source.value,ws=props.workspaceId,request=generation,asset=projection.value?.devices[0]?.asset_id;if(!s||!canImport.value||!s.observed_at||!asset||busy.value)return
  if(!confirm(`Импортировать наблюдения ${s.name} в IPAM? Существующие ручные записи не изменяются.`))return
  busy.value=true;emit('update:mutating',true);error.value='';report.value=null
  try{const result=await api.write<{data:Report}>('POST','/inventory/network-discovery/reconcile',{workspace_id:ws,target_id:s.target_id,asset_id:asset,observed_at:s.observed_at,...(networkId.value.trim()?{network_id:networkId.value.trim()}:{})});if(request===generation)report.value=result.data}
  catch(e){if(request===generation)error.value=apiError(e).message}finally{if(request===generation)busy.value=false;emit('update:mutating',false)}
}
onBeforeUnmount(()=>{generation++})
</script>
<template>
  <section class="panel">
    <h2>Наблюдаемая сетевая топология</h2>
    <p>Текущие снимки SSH, WinRM, SNMP, RouterOS, PVE и VMmanager. Только данные не старше 15 минут; связи не создают редактируемых CMDB-отношений.</p>
    <div v-if="error" class="alert" role="alert">{{error}}</div>
    <div class="inline-actions"><button class="btn" :disabled="busy" @click="refresh">Обновить наблюдения</button><button class="btn" :disabled="busy||offset===0" @click="offset-=limit;refresh()">Назад</button><button class="btn" :disabled="busy||!page?.has_more" @click="offset+=limit;refresh()">Далее</button><label>Источников на страницу <select v-model.number="limit" :disabled="busy" @change="offset=0;refresh()"><option :value="1">1</option><option :value="5">5</option><option :value="10">10</option><option :value="20">20</option></select></label><span>Источники {{offset+1}}–{{offset+(page?.sources.length||0)}}</span></div>
    <div v-if="page" class="field"><label for="network-discovery-source">Источник</label><select id="network-discovery-source" v-model="selected" :disabled="busy"><option value="" disabled>Выберите источник на странице</option><option v-for="s in page.sources" :key="s.target_id" :value="s.target_id">{{s.name}} · {{s.provider}} · {{s.fresh?'актуален':s.state==='available'?'устарел':s.state}}</option></select></div>
    <p v-if="source">Наблюдение: {{source.observed_at||'нет данных'}}. {{projection?.complete?'Полный снимок':'Неполный или недоступный снимок'}}</p>
    <template v-if="projection">
      <div class="field"><label for="network-discovery-search">Интерфейсы (имя, устройство, MAC)</label><input id="network-discovery-search" v-model="search" /></div>
      <div class="table-wrap"><table><thead><tr><th>Устройство</th><th>Интерфейс</th><th>MAC</th><th>VLAN</th></tr></thead><tbody><tr v-for="i in rows" :key="i.id"><td>{{devices.get(i.device_id)}}</td><td>{{i.name}}</td><td>{{i.mac||'—'}}</td><td>{{i.vlan_id??'—'}}</td></tr></tbody></table></div>
      <div class="inline-actions"><button class="btn" :disabled="rowPage===0" @click="rowPage--">Назад</button><button class="btn" :disabled="(rowPage+1)*50>=matching.length" @click="rowPage++">Далее</button><span>{{matching.length}} интерфейсов</span></div>
      <details><summary>Адреса и подсети ({{projection.addresses.length}} адресов, {{projection.networks.length}} сетей)</summary><div class="table-wrap"><table><thead><tr><th>Адрес</th><th>Интерфейс</th><th>Подсеть</th><th>Диагностика</th></tr></thead><tbody><tr v-for="a in addresses" :key="a.id"><td>{{a.address}}</td><td>{{interfaces.get(a.interface_id||'')||'—'}}</td><td>{{networks.get(a.network_id||'')||'неизвестна'}}</td><td>{{a.issue||'—'}}</td></tr></tbody></table></div><ul><li v-for="n in projection.networks" :key="n.id">{{n.cidr}} · VLAN {{n.vlan_id??'—'}} · {{n.scope}}</li></ul></details>
      <details><summary>Логические связи ({{projection.links.length}})</summary><ul><li v-for="l in projection.links" :key="l.id">{{allNics.get(l.source)}} → {{allNics.get(l.target)}} · {{l.kind}}</li></ul></details>
      <details><summary>Соседи и VLAN-наблюдения ({{projection.neighbors.length+projection.issues.length}})</summary><ul><li v-for="(n,i) in projection.neighbors" :key="i">{{interfaces.get(n.interface_id||'')||'не сопоставлен'}} → {{n.name||n.chassis_id||n.mac||'неизвестен'}} ({{n.evidence}})</li><li v-for="(n,i) in projection.issues" :key="'v'+i">{{n.code}}: {{n.vlan_id}} · {{n.bridge||''}} · tagged {{n.tagged}} · untagged {{n.untagged||''}}</li></ul></details>
      <p v-if="canWrite && ['ssh','winrm','routeros'].includes(source?.provider||'') && !projection.devices[0]?.asset_id">Для импорта сначала синхронизируйте базовый Inventory устройства.</p><div v-if="canImport"><h3>IPAM reconciliation</h3><p>Импорт только из выбранного актуального снимка. Для общей уже существующей сети укажите её UUID; CIDR и известный VLAN должны совпасть. Без выбора пересечения считаются конфликтом.</p><div class="field"><label for="network-discovery-reuse">UUID существующей сети (необязательно)</label><input id="network-discovery-reuse" v-model="networkId" :disabled="busy" /></div><button class="btn primary" :disabled="busy" @click="reconcile">Импортировать в IPAM</button></div>
      <p v-if="report" role="status">Создано: {{report.created}} · Без изменений: {{report.unchanged}} · Конфликты: {{report.conflicts}} · Пропущено: {{report.skipped}} · Сохранено ненаблюдаемых: {{report.retained_unseen}}</p><ul v-if="report"><li v-for="(i,j) in report.issues" :key="j">{{i.kind}} · {{i.code}} · {{i.key}}</li></ul>
    </template>
    <template v-if="page"><h3>Подтверждённые LLDP-связи на странице</h3><p v-if="!page.physical_links.length">Нет двусторонних однозначных наблюдений.</p><ul><li v-for="l in page.physical_links" :key="l.id">{{allNics.get(l.source)}} ↔ {{allNics.get(l.target)}} ({{l.evidence}})</li></ul><h3>Повторяющиеся наблюдения</h3><p>Совпадение IP, MAC, CIDR или пересечение префиксов не означает, что источники относятся к одной сети. Показаны совпадения в текущей странице.</p><p v-if="page.conflicts_truncated">Диагностика ограничена первыми 500 совпадениями и пересечениями.</p><ul><li v-for="c in page.conflicts" :key="c.kind+c.value">{{c.kind}} {{c.value}} — {{c.observation_count}} · {{c.code}}</li></ul></template>
  </section>
</template>
