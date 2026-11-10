<script setup lang="ts">
import {apiError} from '~/composables/useApi'
const api=useApi(),auth=useAuth()
type Workspace={id:string;name:string;role:string}
type Entry={id:string;workspace_id:string;slug:string;name:string;description:string;etag:string;version:number;asset_count?:number}
type Asset={id:string;name:string;type:string;deleted_at?:string|null}
const spaces=ref<Workspace[]>([]),workspace=ref(''),tags=ref<Entry[]>([]),groups=ref<Entry[]>([]),assets=ref<Asset[]>([])
const busy=ref(false),error=ref(''),notice=ref(''),kind=ref<'tags'|'groups'>('tags')
const editing=ref<Entry|null>(null),form=reactive({slug:'',name:'',description:''})
const selectedGroup=ref<Entry|null>(null),assetId=ref(''),linked=ref<Asset[]>([])
const canRead=computed(()=>auth.can('inventory.catalog.read'))
const canManage=computed(()=>auth.can('inventory.catalog.manage')&&['owner','manager'].includes(spaces.value.find(x=>x.id===workspace.value)?.role||''))
function query(){return new URLSearchParams({workspace_id:workspace.value})}
async function load(){
  if(!workspace.value)return
  busy.value=true;error.value=''
  try{
    const q=query()
    const [t,g]=await Promise.all([api.get<{data:Entry[]}>(`/inventory/tags?${q}`),api.get<{data:Entry[]}>(`/inventory/groups?${q}`)])
    tags.value=t.data;groups.value=g.data
    if(auth.can('inventory.assets.read'))assets.value=(await api.get<{data:Asset[]}>(`/inventory/assets?${new URLSearchParams({workspace_id:workspace.value,limit:'100'})}`)).data
    else assets.value=[]
    if(selectedGroup.value){selectedGroup.value=groups.value.find(g=>g.id===selectedGroup.value?.id)||null;await loadMembers()}
  }catch(e){error.value=apiError(e).message}finally{busy.value=false}
}
async function start(){
  if(!canRead.value)return
  try{spaces.value=(await api.get<{data:Workspace[]}>('/workspaces')).data;workspace.value=spaces.value[0]?.id||'';await load()}
  catch(e){error.value=apiError(e).message}
}
function reset(){editing.value=null;Object.assign(form,{slug:'',name:'',description:''})}
function edit(row:Entry,k:'tags'|'groups'){kind.value=k;editing.value=row;Object.assign(form,{slug:row.slug,name:row.name,description:row.description})}
async function save(){
  if(!canManage.value)return
  busy.value=true;error.value='';notice.value=''
  try{
    if(editing.value){
      const q=query()
      await api.write('PATCH',`/inventory/${kind.value}/${editing.value.id}?${q}`,{name:form.name,description:form.description},true,{'If-Match':editing.value.etag})
      notice.value='Запись обновлена.'
    }else{
      await api.write('POST',`/inventory/${kind.value}`,{workspace_id:workspace.value,slug:form.slug,name:form.name,description:form.description})
      notice.value='Запись создана.'
    }
    reset();await load()
  }catch(e){error.value=apiError(e).status===412?'Запись изменилась. Обновите список.':apiError(e).message}
  finally{busy.value=false}
}
async function remove(row:Entry,k:'tags'|'groups'){
  if(!canManage.value||!confirm(`Удалить «${row.name}»?`))return
  busy.value=true;error.value=''
  try{await api.write('DELETE',`/inventory/${k}/${row.id}?${query()}`,undefined,true,{'If-Match':row.etag});notice.value='Запись удалена.';await load()}
  catch(e){error.value=apiError(e).message}finally{busy.value=false}
}
async function loadMembers(){
  if(!selectedGroup.value)return
  if(!auth.can('inventory.assets.read')){linked.value=[];return}
  try{linked.value=(await api.get<{data:Asset[]}>(`/inventory/groups/${selectedGroup.value.id}/assets?${query()}`)).data}
  catch(e){error.value=apiError(e).message}
}
async function openGroup(g:Entry){selectedGroup.value=g;assetId.value='';await loadMembers()}
async function membership(asset:string,attach:boolean){
  if(!selectedGroup.value||!canManage.value)return
  busy.value=true;error.value=''
  try{
    const result=await api.write<{data:Entry}>(attach?'POST':'DELETE',`/inventory/groups/${selectedGroup.value.id}/assets/${asset}?${query()}`,undefined,true,{'If-Match':selectedGroup.value.etag})
    selectedGroup.value=result.data;notice.value=attach?'Объект добавлен в группу.':'Объект исключён.'
    await load()
  }catch(e){error.value=apiError(e).status===412?'Состав группы изменился. Обновите страницу.':apiError(e).message}finally{busy.value=false}
}
watch(workspace,()=>{reset();selectedGroup.value=null;load()})
onMounted(start)
</script>
<template>
  <div>
    <h1>Теги и группы CMDB</h1>
    <p class="page-subtitle">Каталог рабочего пространства. Теги доступны в карточке объекта, группы объединяют объекты для поиска.</p>
    <div v-if="error" role="alert" class="alert">{{error}}</div>
    <div v-if="notice" role="status" class="alert success">{{notice}}</div>
    <div v-if="!canRead" class="alert">Нет разрешения inventory.catalog.read.</div>
    <template v-else>
      <section class="panel">
        <div class="field"><label for="catalog-workspace">Пространство</label><select id="catalog-workspace" v-model="workspace"><option v-for="w in spaces" :key="w.id" :value="w.id">{{w.name}} ({{w.role}})</option></select></div>
        <div class="inline-actions"><button type="button" class="btn" :disabled="busy" @click="load">Обновить</button><button v-if="canManage" class="btn" @click="reset">Новая запись</button></div>
      </section>
      <section v-if="canManage" class="panel">
        <h2>{{editing?'Изменить':'Создать'}} {{kind==='tags'?'тег':'группу'}}</h2>
        <form @submit.prevent="save">
          <div class="form-row">
            <div class="field"><label for="catalog-kind">Тип</label><select id="catalog-kind" v-model="kind" :disabled="!!editing"><option value="tags">Тег</option><option value="groups">Группа</option></select></div>
            <div class="field"><label for="catalog-slug">Код</label><input id="catalog-slug" v-model="form.slug" maxlength="64" pattern="[a-z0-9][a-z0-9._-]*" :disabled="!!editing" required /></div>
            <div class="field"><label for="catalog-name">Название</label><input id="catalog-name" v-model="form.name" maxlength="128" required /></div>
            <div class="field"><label for="catalog-description">Описание</label><input id="catalog-description" v-model="form.description" maxlength="1024" /></div>
          </div>
          <div class="inline-actions"><button class="btn primary" :disabled="busy">Сохранить</button><button type="button" class="btn" @click="reset">Сброс</button></div>
        </form>
      </section>
      <section class="panel"><h2>Теги</h2><div class="table-wrap"><table class="table"><thead><tr><th>Код</th><th>Название</th><th>Объектов</th><th>Действия</th></tr></thead><tbody>
        <tr v-for="t in tags" :key="t.id"><td class="mono">{{t.slug}}</td><td>{{t.name}}</td><td>{{t.asset_count}}</td><td><div v-if="canManage" class="inline-actions"><button class="btn" @click="edit(t,'tags')">Изменить</button><button class="btn" @click="remove(t,'tags')">Удалить</button></div></td></tr>
      </tbody></table></div></section>
      <section class="panel"><h2>Группы</h2><div class="table-wrap"><table class="table"><thead><tr><th>Код</th><th>Название</th><th>Объектов</th><th>Действия</th></tr></thead><tbody>
        <tr v-for="g in groups" :key="g.id"><td class="mono">{{g.slug}}</td><td>{{g.name}}</td><td>{{g.asset_count}}</td><td><div class="inline-actions"><button class="btn" @click="openGroup(g)">Состав</button><button v-if="canManage" class="btn" @click="edit(g,'groups')">Изменить</button><button v-if="canManage" class="btn" @click="remove(g,'groups')">Удалить</button></div></td></tr>
      </tbody></table></div></section>
      <section v-if="selectedGroup" class="panel"><h2>Состав группы «{{selectedGroup.name}}»</h2>
        <form v-if="canManage&&auth.can('inventory.assets.read')" class="inline-actions" @submit.prevent="membership(assetId,true)"><select v-model="assetId" aria-label="Объект для добавления" required><option value="">Выберите объект</option><option v-for="a in assets.filter(a=>!linked.some(l=>l.id===a.id))" :key="a.id" :value="a.id">{{a.name}} — {{a.type}}</option></select><button class="btn primary" :disabled="busy||!assetId">Добавить</button></form>
        <div v-if="!auth.can('inventory.assets.read')" class="alert">Для просмотра объектов требуется inventory.assets.read.</div>
        <div v-else-if="!linked.length" class="empty">В группе нет объектов.</div>
        <div v-for="a in linked" :key="a.id" class="inline-actions"><span>{{a.name}} ({{a.type}})<span v-if="a.deleted_at"> — удалён</span></span><button v-if="canManage" type="button" class="btn" :disabled="busy" @click="membership(a.id,false)">Исключить</button></div>
      </section>
    </template>
  </div>
</template>
