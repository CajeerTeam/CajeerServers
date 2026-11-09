<script setup lang="ts">
import { apiError } from '~/composables/useApi'
const api=useApi()
const auth=useAuth()
type Workspace={id:string;name:string;role:'owner'|'manager'|'viewer'}
type Asset={id:string;name:string;type:string;etag:string}
type Relationship={id:string;workspace_id:string;source_asset_id:string;target_asset_id:string;kind:string;etag:string;version:number}
type GraphNode={id:string;entity_id:string;kind:string;subtype:string;label:string;status?:string}
type GraphEdge={id:string;source:string;target:string;kind:string;derived:boolean}
type Graph={nodes:GraphNode[];edges:GraphEdge[];truncated:boolean;limits:Record<string,number>}
const types=[
  {value:'cluster_contains',label:'Кластер содержит узел'},
  {value:'hosts',label:'Хост размещает VM/контейнер'},
  {value:'depends_on',label:'Зависит от'},
  {value:'connected_to',label:'Подключено к'},
]
const spaces=ref<Workspace[]>([])
const workspaceId=ref('')
const assets=ref<Asset[]>([])
const relations=ref<Relationship[]>([])
const graph=ref<Graph|null>(null)
const busy=ref(false)
const error=ref('')
const notice=ref('')
const sourceId=ref('')
const targetId=ref('')
const relationKind=ref('hosts')
const selectedRelation=ref<Relationship|null>(null)
const canRead=computed(()=>auth.can('inventory.relationships.read') && auth.can('inventory.assets.read') && auth.can('inventory.network.read'))
const space=computed(()=>spaces.value.find(w=>w.id===workspaceId.value))
const canWrite=computed(()=>auth.can('inventory.relationships.manage') && ['owner','manager'].includes(space.value?.role||''))
const names=computed(()=>new Map(assets.value.map(a=>[a.id,a.name])))
const nodeKindName=(kind:string)=>({asset:'Устройство',network:'Сеть',interface:'Интерфейс',address:'IP'} as Record<string,string>)[kind]||kind
const relationName=(kind:string)=>types.find(t=>t.value===kind)?.label||kind
function report(e:unknown){error.value=apiError(e).status===412?'Объект изменён другим пользователем. Обновите данные.':apiError(e).message}
function reset(){sourceId.value='';targetId.value='';relationKind.value='hosts';selectedRelation.value=null}
function edit(item:Relationship){selectedRelation.value=item;sourceId.value=item.source_asset_id;targetId.value=item.target_asset_id;relationKind.value=item.kind}
async function init(){
  if(!canRead.value)return
  busy.value=true
  try {
    spaces.value=(await api.get<{data:Workspace[]}>('/workspaces')).data
    workspaceId.value=spaces.value[0]?.id||''
    await refresh()
  }catch(e){report(e)}finally{busy.value=false}
}
async function refresh(){
  if(!workspaceId.value){graph.value=null;relations.value=[];assets.value=[];return}
  busy.value=true;error.value=''
  try {
    const qs=`workspace_id=${encodeURIComponent(workspaceId.value)}`
    const [g,r,a]=await Promise.all([
      api.get<{data:Graph}>(`/inventory/topology?${qs}&limit=100`),
      api.get<{data:Relationship[]}>(`/inventory/relationships?${qs}&limit=100`),
      auth.can('inventory.assets.read')?api.get<{data:Asset[]}>(`/inventory/assets?${qs}&limit=100`):Promise.resolve({data:[] as Asset[]}),
    ])
    graph.value=g.data;relations.value=r.data;assets.value=a.data
    reset()
  }catch(e){report(e)}finally{busy.value=false}
}
async function save(){
  if(!canWrite.value)return
  busy.value=true;error.value='';notice.value=''
  try{
    if(selectedRelation.value){
      await api.write('PATCH',`/inventory/relationships/${selectedRelation.value.id}?workspace_id=${workspaceId.value}`,{kind:relationKind.value},true,{'If-Match':selectedRelation.value.etag})
      notice.value='Тип отношения изменён.'
    }else{
      await api.write('POST','/inventory/relationships',{workspace_id:workspaceId.value,source_asset_id:sourceId.value,target_asset_id:targetId.value,kind:relationKind.value})
      notice.value='Отношение создано.'
    }
    await refresh()
  }catch(e){report(e)}finally{busy.value=false}
}
async function remove(item:Relationship){
  if(!canWrite.value||!confirm('Удалить связь между инфраструктурными объектами?'))return
  busy.value=true;error.value='';notice.value=''
  try{
    await api.write('DELETE',`/inventory/relationships/${item.id}?workspace_id=${workspaceId.value}`,undefined,true,{'If-Match':item.etag})
    notice.value='Связь удалена.';await refresh()
  }catch(e){report(e)}finally{busy.value=false}
}
// Layout is derived from real API nodes and edges, never from hard-coded mock data.
const visible=computed(()=>graph.value?.nodes.slice(0,60)||[])
const position=computed(()=>{
  const groups:Record<number,GraphNode[]>={0:[],1:[],2:[],3:[],4:[],5:[]}
  for(const node of visible.value){
    const col=node.kind==='network'?5:node.kind==='address'?4:node.kind==='interface'?3:
      node.subtype==='cluster'?0:['hypervisor_node','server','router'].includes(node.subtype)?1:2
    groups[col]!.push(node)
  }
  const map:Record<string,{x:number;y:number}>={}
  for(const [col,list] of Object.entries(groups))for(let i=0;i<list.length;i++){
    map[list[i]!.id]={x:90+Number(col)*175,y:45+i*72}
  }
  return map
})
const graphHeight=computed(()=>Math.max(370,...Object.values(position.value).map(p=>p.y+50)))
const drawnEdges=computed(()=>graph.value?.edges.filter(e=>position.value[e.source]&&position.value[e.target]).slice(0,150)||[])
onMounted(init)
</script>
<template>
  <div>
    <h1>Связи и топология инфраструктуры</h1>
    <p class="page-subtitle">Реальные CMDB-связи и связи IPAM. Управление серверами и Proxmox в этом разделе не выполняется.</p>
    <div v-if="error" class="alert" role="alert">{{error}}</div>
    <div v-if="notice" class="alert success" role="status">{{notice}}</div>
    <div v-if="!canRead" class="alert">Для графа нужны inventory.relationships.read, inventory.assets.read и inventory.network.read.</div>
    <template v-else>
      <section class="panel">
        <div class="field"><label for="topology-workspace">Рабочее пространство</label>
          <select id="topology-workspace" v-model="workspaceId" :disabled="busy" @change="refresh">
            <option v-for="w in spaces" :key="w.id" :value="w.id">{{w.name}} ({{w.role}})</option>
          </select>
        </div>
        <button type="button" class="btn" :disabled="busy||!workspaceId" @click="refresh">Обновить</button>
      </section>
      <section v-if="canWrite" class="panel">
        <h2>{{selectedRelation?'Изменение типа связи':'Новая связь'}}</h2>
        <form @submit.prevent="save">
          <div class="form-row">
            <div class="field"><label for="topo-source">Откуда</label>
              <select id="topo-source" v-model="sourceId" required :disabled="!!selectedRelation">
                <option value="">Выберите объект</option>
                <option v-for="a in assets" :key="a.id" :value="a.id">{{a.name}} — {{a.type}}</option>
              </select>
            </div>
            <div class="field"><label for="topo-kind">Тип связи</label>
              <select id="topo-kind" v-model="relationKind"><option v-for="t in types" :key="t.value" :value="t.value">{{t.label}}</option></select>
            </div>
            <div class="field"><label for="topo-target">Куда</label>
              <select id="topo-target" v-model="targetId" required :disabled="!!selectedRelation">
                <option value="">Выберите объект</option>
                <option v-for="a in assets" :key="a.id" :value="a.id">{{a.name}} — {{a.type}}</option>
              </select>
            </div>
          </div>
          <div class="inline-actions">
            <button class="btn primary" type="submit" :disabled="busy||!sourceId||!targetId||sourceId===targetId">{{selectedRelation?'Сохранить':'Связать объекты'}}</button>
            <button v-if="selectedRelation" class="btn" type="button" @click="reset">Отмена</button>
          </div>
        </form>
      </section>
      <section class="panel">
        <h2>Топология</h2>
        <p v-if="graph?.truncated" class="alert">Граф ограничен по размеру. Для полного списка используйте API с пагинацией.</p>
        <p v-if="(graph?.nodes.length||0)>60" class="page-subtitle">На схеме показаны первые 60 узлов; остальные доступны в API.</p>
        <div style="overflow:auto;max-height:650px;border:1px solid var(--border, #666);border-radius:8px">
          <svg v-if="visible.length" role="img" aria-label="Граф инфраструктуры" :viewBox="`0 0 1060 ${graphHeight}`" width="1060" :height="graphHeight">
            <defs><marker id="topology-arrow" markerWidth="7" markerHeight="7" refX="5" refY="3" orient="auto"><path d="M0 0 L6 3 L0 6" fill="none" stroke="currentColor" /></marker></defs>
            <g v-for="edge in drawnEdges" :key="edge.id">
              <line :x1="position[edge.source]?.x" :y1="position[edge.source]?.y" :x2="position[edge.target]?.x" :y2="position[edge.target]?.y" stroke="#7d8fa5" stroke-width="1.4" :stroke-dasharray="edge.derived?'4 4':''" marker-end="url(#topology-arrow)" />
            </g>
            <g v-for="node in visible" :key="node.id" :transform="`translate(${position[node.id]?.x||0} ${position[node.id]?.y||0})`">
              <circle r="11" :fill="node.kind==='asset'?'#3574bb':node.kind==='network'?'#2b8961':node.kind==='interface'?'#bd8732':'#8866b0'" />
              <text y="-17" text-anchor="middle" font-size="11" fill="currentColor">{{node.label.slice(0,25)}}</text>
              <text y="25" text-anchor="middle" font-size="10" fill="currentColor">{{nodeKindName(node.kind)}}</text>
            </g>
          </svg>
          <p v-else class="page-subtitle">В выбранном пространстве пока нет объектов.</p>
        </div>
        <p class="page-subtitle">Сплошная линия — явно зарегистрированная связь; пунктирная — связь на основе IPAM.</p>
      </section>
      <section class="panel">
        <h2>Зарегистрированные связи ({{relations.length}})</h2>
        <table><thead><tr><th>Источник</th><th>Связь</th><th>Цель</th><th>Действия</th></tr></thead>
          <tbody><tr v-for="item in relations" :key="item.id">
            <td>{{names.get(item.source_asset_id)||item.source_asset_id}}</td>
            <td>{{relationName(item.kind)}}</td>
            <td>{{names.get(item.target_asset_id)||item.target_asset_id}}</td>
            <td><template v-if="canWrite"><button class="btn" type="button" :disabled="busy" @click="edit(item)">Изменить</button> <button class="btn" type="button" :disabled="busy" @click="remove(item)">Удалить</button></template><span v-else>—</span></td>
          </tr></tbody>
        </table>
        <p class="page-subtitle">Показаны первые 100 связей, полный реестр доступен через API.</p>
      </section>
    </template>
  </div>
</template>
