<script setup lang="ts">
const api=useApi(),auth=useAuth()
type Workspace={id:string;name:string;role:string}
type Script={id:string;name:string;description:string;revision:number;etag?:string;archived_at:string|null}
type Version={id:string;version:number;state:string;source?:string;signing_bytes?:string;key_id?:string;key_revoked_at?:string|null}
type Key={id:string;name:string;public_key:string;revoked_at:string|null}
type Template={id:string;provider:string;language:string;source:string}
const spaces=ref<Workspace[]>([]),workspace=ref(''),scripts=ref<Script[]>([]),keys=ref<Key[]>([]),templates=ref<Template[]>([]),versions=ref<Version[]>([])
const scriptOffset=ref(0),keyOffset=ref(0),versionOffset=ref(0)
const selected=ref<Script|null>(null),version=ref<Version|null>(null),error=ref(''),busy=ref(false),notice=ref('')
const name=ref(''),description=ref(''),template=ref(''),language=ref('python'),provider=ref('local'),source=ref(''),targets=ref(''),schema=ref('[]'),timeout=ref(60),output=ref(262144)
const keyName=ref(''),publicKey=ref(''),keyId=ref(''),signature=ref(''),password=ref(''),totp=ref(''),target=ref(''),params=ref('{}')
const base=computed(()=>`/workspaces/${workspace.value}/scripts`)
const role=computed(()=>spaces.value.find(s=>s.id===workspace.value)?.role)
const manage=computed(()=>auth.can('scripts.manage')&&['owner','manager'].includes(role.value||''))
const approve=computed(()=>auth.can('scripts.approve')&&role.value==='owner')
const execute=computed(()=>auth.can('scripts.execute')&&['owner','manager'].includes(role.value||''))
async function run(fn:()=>Promise<void>){if(busy.value)return;busy.value=true;error.value='';notice.value='';try{await fn()}catch(e){error.value=apiError(e).message}finally{busy.value=false;password.value='';totp.value=''}}
async function load(){scriptOffset.value=0;keyOffset.value=0;versionOffset.value=0;selected.value=null;version.value=null;versions.value=[];signature.value='';keyId.value='';scripts.value=[];keys.value=[];templates.value=[];if(!workspace.value)return;const [a,b,c]=await Promise.all([api.get<{data:Script[]}>(base.value),api.get<{data:Key[]}>(base.value+'/keys'),api.get<{data:Template[]}>(base.value+'/templates')]);scripts.value=a.data;keys.value=b.data;templates.value=c.data}
async function open(id:string){versionOffset.value=0;version.value=null;const a=await api.get<{data:Script}>(base.value+'/'+id);selected.value=a.data;name.value=a.data.name;description.value=a.data.description;versions.value=(await api.get<{data:Version[]}>(base.value+'/'+id+'/versions')).data}
async function page(kind:'scripts'|'keys'|'versions',delta:number){
  const pos=kind==='scripts'?scriptOffset:kind==='keys'?keyOffset:versionOffset
  const offset=Math.max(0,pos.value+delta)
  if(kind==='scripts')scripts.value=(await api.get<{data:Script[]}>(base.value+`?offset=${offset}`)).data
  else if(kind==='keys')keys.value=(await api.get<{data:Key[]}>(base.value+`/keys?offset=${offset}`)).data
  else if(selected.value)versions.value=(await api.get<{data:Version[]}>(base.value+`/${selected.value.id}/versions?offset=${offset}`)).data
  pos.value=offset
}
async function changeWorkspace(){source.value='';schema.value='[]';targets.value='';target.value='';params.value='{}';name.value='';description.value='';signature.value='';password.value='';totp.value='';await load()}
async function openVersion(id:string){version.value=(await api.get<{data:Version}>(base.value+'/'+selected.value!.id+'/versions/'+id)).data;signature.value=''}
async function create(){const a=await api.write<{data:Script}>('POST',base.value,{name:name.value,description:description.value});await load();await open(a.data.id)}
async function metadata(archive=false){const s=selected.value!;await api.write(archive?'DELETE':'PATCH',base.value+'/'+s.id,archive?undefined:{name:name.value,description:description.value},true,{'If-Match':s.etag!});await load();await open(s.id)}
async function addVersion(){const id=selected.value!.id;const target_ids=targets.value.split(',').map(s=>s.trim()).filter(Boolean);const body=template.value?{template:template.value,target_ids}:{language:language.value,provider:provider.value,source:source.value,target_ids,parameters:JSON.parse(schema.value),timeout_seconds:timeout.value,output_limit_bytes:output.value};const a=await api.write<{data:Version}>('POST',base.value+'/'+id+'/versions',body);await open(id);await openVersion(a.data.id)}
async function changeKey(revoke?:string){await api.write('POST',base.value+'/keys'+(revoke?'/'+revoke+'/revoke':''),revoke?{password:password.value,totp:totp.value}:{name:keyName.value,public_key:publicKey.value,password:password.value,totp:totp.value});keyOffset.value=0;keys.value=(await api.get<{data:Key[]}>(base.value+'/keys')).data;if(selected.value)await open(selected.value.id)}
async function changeVersion(action:string){const id=selected.value!.id,v=version.value!.id;const body=action==='sign'?{key_id:keyId.value,signature:signature.value}:action==='validate'?{...(target.value?{target_id:target.value}:{}),parameters:JSON.parse(params.value)}:{password:password.value,totp:totp.value};await api.write('POST',base.value+'/'+id+'/versions/'+v+'/'+action,body);await open(id);await openVersion(v);notice.value=action==='validate'?'Подпись, состояние, target и параметры проверены. Исполнение отключено.':'Изменение сохранено.'}
function downloadBytes(){if(!version.value?.signing_bytes)return;const url=URL.createObjectURL(new Blob([version.value.signing_bytes],{type:'application/octet-stream'}));const a=document.createElement('a');a.href=url;a.download='script-signing-bytes.json';a.click();URL.revokeObjectURL(url)}
onMounted(()=>run(async()=>{if(!auth.can('scripts.read'))return;spaces.value=(await api.get<{data:Workspace[]}>('/workspaces')).data;workspace.value=spaces.value[0]?.id||'';await load()}))
</script>
<template>
  <div><h1>Реестр скриптов</h1><p class="page-subtitle">Неизменяемые версии, подписи Ed25519 и утверждение. Исполнение пользовательского кода пока отключено.</p>
    <UiAlert :message="error"/><p v-if="notice" role="status">{{notice}}</p>
    <section v-if="auth.can('scripts.read')"><label>Workspace <select v-model="workspace" :disabled="busy" @change="run(changeWorkspace)"><option v-for="w in spaces" :key="w.id" :value="w.id">{{w.name}}</option></select></label>
      <div v-if="workspace" class="card"><h2>Скрипты</h2><button v-for="s in scripts" :key="s.id" class="btn" :disabled="busy" @click="run(()=>open(s.id))">{{s.name}} {{s.archived_at?'(архив)':''}}</button>
      <div><button class="btn" :disabled="busy||scriptOffset===0" @click="run(()=>page('scripts',-50))">Назад</button><button class="btn" :disabled="busy||scripts.length!==50" @click="run(()=>page('scripts',50))">Далее</button></div>
      <div v-if="manage"><label>Название <input v-model="name" maxlength="255"/></label><label>Описание <input v-model="description" maxlength="1024"/></label><button class="btn" :disabled="busy||!name" @click="run(create)">Создать скрипт</button><button v-if="selected&&!selected.archived_at" class="btn" :disabled="busy" @click="run(()=>metadata())">Сохранить метаданные</button><button v-if="selected&&!selected.archived_at" class="btn" :disabled="busy" @click="run(()=>metadata(true))">Архивировать</button></div></div>
      <div v-if="selected" class="card"><h2>{{selected.name}} — версии</h2><button v-for="v in versions" :key="v.id" class="btn" :disabled="busy" @click="run(()=>openVersion(v.id))">v{{v.version}} · {{v.state}}</button>
      <div><button class="btn" :disabled="busy||versionOffset===0" @click="run(()=>page('versions',-50))">Назад</button><button class="btn" :disabled="busy||versions.length!==50" @click="run(()=>page('versions',50))">Далее</button></div>
      <div v-if="manage&&!selected.archived_at"><h3>Новая версия</h3><label>Шаблон <select v-model="template"><option value="">Свой код</option><option v-for="t in templates" :key="t.id" :value="t.id">{{t.id}}</option></select></label><label>UUID targets через запятую <input v-model="targets"/></label>
        <template v-if="!template"><label>Язык <select v-model="language"><option>python</option><option>bash</option><option>powershell</option><option>connector</option></select></label><label>Провайдер <select v-model="provider"><option v-for="p in ['local','ssh','winrm','snmp','proxmox','vmmanager']" :key="p">{{p}}</option></select></label><label>Исходный код (без секретов) <textarea v-model="source" rows="8" maxlength="65536"/></label><label>Схема параметров JSON <textarea v-model="schema" rows="3"/></label><label>Timeout (сек) <input v-model.number="timeout" type="number" min="1" max="90"/></label><label>Лимит вывода <input v-model.number="output" type="number" min="1024" max="262144"/></label></template><button class="btn" :disabled="busy" @click="run(addVersion)">Создать draft</button></div>
      <div v-if="version"><h3>Версия {{version.version}} — {{version.state}}</h3><pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{version.source}}</pre><details><summary>Подписываемый manifest</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{version.signing_bytes}}</pre></details><button class="btn" @click="downloadBytes">Скачать точные байты для подписи</button>
        <div v-if="manage&&version.state==='draft'&&!selected.archived_at"><label>Доверенный ключ <select v-model="keyId"><option v-for="k in keys.filter(k=>!k.revoked_at)" :key="k.id" :value="k.id">{{k.name}}</option></select></label><label>Подпись hex <input v-model="signature" maxlength="128"/></label><button class="btn" :disabled="busy||!keyId||signature.length!==128" @click="run(()=>changeVersion('sign'))">Передать на утверждение</button></div>
        <div v-if="approve&&version.state!=='revoked'"><button v-if="version.state==='pending_review'&&!selected.archived_at" class="btn" :disabled="busy||!password||totp.length!==6" @click="run(()=>changeVersion('approve'))">Утвердить (MFA ниже)</button><button class="btn" :disabled="busy||!password||totp.length!==6" @click="run(()=>changeVersion('revoke'))">Отозвать версию (MFA ниже)</button></div>
        <div v-if="execute&&version.state==='approved'&&!selected.archived_at"><label>Target UUID (пусто для local) <input v-model="target"/></label><label>Параметры JSON <textarea v-model="params"/></label><button class="btn" :disabled="busy" @click="run(()=>changeVersion('validate'))">Проверить без исполнения</button></div></div></div>
      <div class="card"><h2>Доверенные ключи</h2><p v-for="k in keys" :key="k.id">{{k.name}} · {{k.revoked_at?'отозван':'действует'}} <code>{{k.public_key}}</code><button v-if="approve&&!k.revoked_at" class="btn" :disabled="busy||!password||totp.length!==6" @click="run(()=>changeKey(k.id))">Отозвать</button></p>
        <div><button class="btn" :disabled="busy||keyOffset===0" @click="run(()=>page('keys',-50))">Назад</button><button class="btn" :disabled="busy||keys.length!==50" @click="run(()=>page('keys',50))">Далее</button></div>
        <div v-if="approve"><h3>Свежая MFA-проверка</h3><label>Пароль <input v-model="password" type="password" autocomplete="current-password"/></label><label>TOTP <input v-model="totp" inputmode="numeric" maxlength="6" autocomplete="one-time-code"/></label><label>Имя ключа <input v-model="keyName"/></label><label>Публичный ключ Ed25519 hex <input v-model="publicKey" maxlength="64"/></label><button class="btn" :disabled="busy||!password||totp.length!==6||publicKey.length!==64" @click="run(()=>changeKey())">Добавить доверенный ключ</button></div></div>
    </section><p v-else>Недостаточно прав.</p>
  </div>
</template>
