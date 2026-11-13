import {test,expect,type Page,type APIRequestContext} from '@playwright/test'
import {createHmac,randomUUID} from 'node:crypto'
import {freshAdminTotp} from './admin-totp'

const api='/api/v1', enabled=process.env.E2E_CONNECTOR_UI==='1'
const host=process.env.E2E_CONNECTOR_FIXTURE_HOST||'',port=Number(process.env.E2E_CONNECTOR_FIXTURE_PORT||8443)
const fixturePin=process.env.E2E_CONNECTOR_FIXTURE_PIN||''
const password=process.env.E2E_ADMIN_PASSWORD||''
// Do not write credentials, TOTP or one-time webhook keys to traces/screenshots/videos.
test.use({trace:'off',screenshot:'off',video:'off'})
async function csrf(r:APIRequestContext){const res=await r.get(api+'/auth/csrf');expect(res.ok()).toBe(true);return (await res.json()).csrf_token as string}
async function post<T>(r:APIRequestContext,path:string,data:unknown,headers:Record<string,string>={}):Promise<T>{const res=await r.post(api+path,{headers:{'X-CSRF-Token':await csrf(r),...headers},data});expect(res.ok(),`POST ${path}: ${res.status()}`).toBe(true);return await res.json() as T}
async function get<T>(r:APIRequestContext,path:string):Promise<T>{const res=await r.get(api+path);expect(res.ok(),`GET ${path}: ${res.status()}`).toBe(true);return (await res.json()).data as T}
async function mfa(page:Page){const code=await freshAdminTotp();expect(code).toMatch(/^\d{6}$/);const root=page.getByTestId('connector-lifecycle');await root.getByTestId('lifecycle-password').fill(password);await root.getByTestId('lifecycle-totp').fill(code)}
async function login(page:Page){await page.goto('/login');await page.getByLabel('Email').fill(process.env.E2E_ADMIN_EMAIL||'');await page.getByLabel('Пароль').fill(password);await page.getByLabel(/Код TOTP/).fill(await freshAdminTotp());await page.getByRole('button',{name:'Войти',exact:true}).click();await expect(page).toHaveURL(/\/$/)}

test('Connector lifecycle: real UI, Vault MFA, scheduled worker, signed event and verified power task',async({page,browser})=>{
  test.skip(!enabled,'Enable E2E_CONNECTOR_UI only for a disposable staging deployment with the supplied TLS fixture')
  test.setTimeout(480_000)
  expect(process.env.E2E_ISOLATED_STAGING).toBe('1');expect(process.env.E2E_ALLOW_WRITES).toBe('1');expect(process.env.E2E_ADMIN_TOTP_SECRET).toBeTruthy();expect(host).toBeTruthy();expect(port).toBeGreaterThan(0);expect(fixturePin).toMatch(/^[a-f0-9]{64}$/)
  await login(page);const r=page.request, label=`connector-e2e-${randomUUID().slice(0,8)}`
  const spaces=await get<{id:string;organization_id:string;role:string}[]>(r,'/workspaces');const owner=spaces.find(s=>s.role==='owner');expect(owner).toBeTruthy()
  // A new isolated workspace avoids matching or modifying existing infrastructure. It is discarded with the staging DB.
  const ws=(await post<{id:string}>(r,'/workspaces',{organization_id:owner!.organization_id,slug:label,name:label})).id
  const asset=(await post<{data:{id:string}}>(r,'/inventory/assets',{workspace_id:ws,type:'cluster',name:label})).data.id
  const credential=(await post<{data:{id:string}}>(r,'/vault/credentials',{workspace_id:ws,name:label,type:'proxmox_api',secret:{token_id:'cajeer-e2e@pve!fixture',token_secret:'fixture-only-cajeer-token'}})).data.id
  let target='',grant='',source='',viewerId=''
  const lifecycle=page.getByTestId('connector-lifecycle')
  const path=()=>`/connectors/targets/${target}`
  const tab=async(name:string)=>{await lifecycle.getByRole('button',{name,exact:true}).click()}
  const runs=()=>get<{id:string;status:string;grant_id:string;operation:string}[]>(r,path()+'/runs?limit=50')
  try{
    await page.goto(`/connectors?workspace_id=${ws}`);await expect(page.getByRole('heading',{name:'Конфигурации подключений и Vault'})).toBeVisible()
    const create=page.locator('section.panel').filter({has:page.getByRole('heading',{name:'Новая конфигурация',exact:true})})
    await create.getByLabel('Название').fill(label);await create.getByLabel('Провайдер').selectOption('proxmox');await create.getByLabel(/Адрес \(DNS/).fill(host);await create.getByLabel('Порт',{exact:true}).fill(String(port));await create.getByLabel(/Inventory asset \(необязательно/).selectOption(asset)
    await create.getByRole('button',{name:'Создать конфигурацию'}).click();await expect(page.getByRole('heading',{name:`Credentials: ${label}`})).toBeVisible()
    target=(await get<{id:string}[]>(r,`/connectors/targets?workspace_id=${ws}&search=${label}`))[0]!.id
    const profileTarget=await get<{etag:string}>(r,path());const profile=await r.patch(api+path(),{headers:{'X-CSRF-Token':await csrf(r),'If-Match':profileTarget.etag},data:{proxmox_profile:{cluster:null,anchor_node:'cajeer-e2e-pve',anchor_id:null,primary_pin:fixturePin,fallbacks:[]}}});expect(profile.ok()).toBe(true);await page.reload();await page.getByTestId('connector-search').fill(label);await page.getByRole('button',{name:'Найти подключения'}).click();await page.getByRole('button',{name:'Привязки',exact:true}).click();
    const bind=page.locator('form').filter({has:page.getByRole('button',{name:'Привязать credential'})});await bind.getByLabel('Credential ID').selectOption(credential);await bind.getByRole('button',{name:'Привязать credential'}).click();await expect(page.getByText('Credential привязан без чтения секрета.')).toBeVisible()
    // Edit through UI, then reject stale ETag and missing CSRF at actual backend.
    const old=await get<{etag:string}>(r,path());await page.getByTestId('connector-edit-name').fill(label+'-edited');page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'Сохранить настройки'}).click();await expect(page.getByText('Конфигурация обновлена.')).toBeVisible()
    const stale=await r.patch(api+path(),{headers:{'X-CSRF-Token':await csrf(r),'If-Match':old.etag},data:{name:'stale'}});expect(stale.status()).toBe(412)
    const denied=await r.post(api+path()+'/runs',{data:{grant_id:randomUUID()},headers:{'Idempotency-Key':randomUUID()}});expect(denied.status()).toBe(403)
    await page.getByTestId('connector-search').fill(label);await page.getByRole('button',{name:'Найти подключения'}).click();await expect(page.getByRole('button',{name:'Привязки',exact:true})).toHaveCount(1);await page.getByRole('button',{name:'Привязки',exact:true}).click()
    await tab('Разрешения и расписания');await lifecycle.getByTestId('grant-operation').selectOption('sync');await lifecycle.getByTestId('grant-ttl').fill('1200');await lifecycle.getByTestId('grant-scheduled').check();await lifecycle.getByTestId('grant-interval').fill('300');await mfa(page);await lifecycle.getByRole('button',{name:'Выдать разрешение'}).click();await expect(lifecycle.getByRole('status')).toContainText('Расписание активировано')
    grant=(await get<{id:string;interval_seconds:number;operation:string}[]>(r,path()+'/service-grants'))[0]!.id
    // Scheduler must enqueue and a real worker must use Vault -> HTTPS -> reconciliation, with no browser route mocks.
    await tab('Запуски');await expect.poll(async()=> (await runs()).some(x=>x.grant_id===grant&&x.status==='succeeded'),{timeout:90000,intervals:[1000,2000,5000]}).toBe(true)
    const first=(await get<{external_id:string;asset_id:string;observed_name:string}[]>(r,path()+'/resources')).find(x=>x.external_id==='qemu:101');expect(first?.observed_name).toBe('cajeer-e2e-vm');expect(first?.asset_id).toBeTruthy()
    await lifecycle.getByTestId('run-grant').selectOption(grant);await lifecycle.getByTestId('run-collector').selectOption('proxmox.inventory');await lifecycle.getByRole('button',{name:'Поставить в очередь'}).click();await expect(lifecycle.getByRole('status')).toContainText('Запуск поставлен');await expect(lifecycle.getByTestId('run-detail')).toContainText('succeeded',{timeout:90000});await expect(lifecycle.getByTestId('run-detail')).toContainText('proxmox.inventory');await expect(lifecycle.getByTestId('run-detail')).toContainText('observations')
    const again=(await get<{external_id:string;asset_id:string}[]>(r,path()+'/resources')).filter(x=>x.external_id==='qemu:101');expect(again).toHaveLength(1);expect(again[0]!.asset_id).toBe(first!.asset_id)
    // Signed events are accepted and dispatched; key is not returned by subsequent list calls.
    await tab('Webhook и события');await lifecycle.getByTestId('webhook-grant').selectOption(grant);await lifecycle.getByRole('button',{name:'Создать источник'}).click();await expect(lifecycle.getByTestId('webhook-one-time')).toBeVisible()
    let key=await lifecycle.getByTestId('webhook-secret').inputValue();expect(key.length).toBe(64);source=(await get<{id:string}[]>(r,path()+'/webhook-sources'))[0]!.id
    await lifecycle.getByRole('button',{name:'Закрыть ключ'}).click();await expect(lifecycle.getByTestId('webhook-one-time')).toHaveCount(0)
    const eventPath=api+`/connectors/webhooks/${source}/events`, raw=JSON.stringify({event_id:label,type:'resource.updated',resource_id:'qemu:101'}),timestamp=String(Math.floor(Date.now()/1000));const signature='sha256='+createHmac('sha256',key).update(`v1.${timestamp}.${source}\n${raw}`).digest('hex');key=''
    const headers={'Content-Type':'application/json','X-Cajeer-Timestamp':timestamp,'X-Cajeer-Signature':signature}
    const invalid=await r.post(eventPath,{data:raw,headers:{...headers,'X-Cajeer-Signature':'sha256='+'0'.repeat(64)}});expect(invalid.status()).toBe(401)
    const accepted=await r.post(eventPath,{data:raw,headers});expect(accepted.status()).toBe(202);const event=(await accepted.json()).data
    const dup=await r.post(eventPath,{data:raw,headers});expect(dup.status()).toBe(202);expect((await dup.json()).data.run_id).toBe(event.run_id)
    await expect(lifecycle.locator('tr').filter({hasText:label})).toBeVisible({timeout:20000});await expect.poll(async()=> (await get<{id:string;status:string}[]>(r,path()+'/events')).find(e=>e.id===event.id)?.status,{timeout:90000}).toBe('succeeded')
    // Only the synthetic fixture VM is eligible. Power action is opt-in on staging, no real VM may be targeted.
    await tab('Удалённые действия');await expect(lifecycle.getByTestId('power-resource')).toBeVisible();await lifecycle.getByTestId('power-resource').selectOption('qemu:101');await lifecycle.getByTestId('power-confirm').fill('wrong');await mfa(page);await expect(lifecycle.getByRole('button',{name:'Выполнить действие'})).toBeDisabled();await lifecycle.getByTestId('power-confirm').fill('vm.start qemu:101');await lifecycle.getByRole('button',{name:'Выполнить действие'}).click();await expect(lifecycle.getByTestId('action-detail')).toContainText('submitted',{timeout:20000});await mfa(page);await lifecycle.getByRole('button',{name:'Проверить удалённую задачу'}).click();await expect(lifecycle.getByTestId('action-detail')).toContainText('succeeded')
    // Source and grant revocation from UI. Existing events stay in history, new events are rejected.
    await tab('Webhook и события');page.once('dialog',d=>d.accept());await lifecycle.getByRole('button',{name:'Отозвать источник'}).click();await expect(lifecycle.getByRole('status')).toContainText('Источник отозван');expect((await r.post(eventPath,{data:raw,headers})).status()).toBe(401)
    await tab('Разрешения и расписания');page.once('dialog',d=>d.accept());await lifecycle.getByRole('button',{name:'Отозвать разрешение'}).click();await expect(lifecycle.getByRole('status')).toContainText('Разрешение отозвано')
    const revoked=await r.post(api+path()+'/runs',{headers:{'X-CSRF-Token':await csrf(r),'Idempotency-Key':randomUUID()},data:{grant_id:grant}});expect(revoked.status()).toBe(403)
    // A user with global read permissions and workspace viewer role sees history, but cannot mutate or cross workspaces.
    const viewerEmail=`${label}@example.invalid`,viewerPass=`fixture-${randomUUID()}-only`,roleSlug=`${label}-viewer`
    viewerId=(await post<{id:string}>(r,'/security/users',{email:viewerEmail,name:label+' viewer',password:viewerPass})).id
    await post(r,'/security/roles',{slug:roleSlug,name:label+' viewer',permissions:['workspaces.read','connectors.targets.read','connectors.credentials.read','inventory.assets.read','connectors.actions.read']})
    const assigned=await r.post(api+`/security/users/${viewerId}/roles/${roleSlug}`,{headers:{'X-CSRF-Token':await csrf(r)}});expect(assigned.ok()).toBe(true)
    const member=await r.put(api+`/workspaces/${ws}/members/${viewerId}`,{headers:{'X-CSRF-Token':await csrf(r)},data:{role:'viewer'}});expect(member.status()).toBe(204)
    const viewer=await browser.newContext({baseURL:process.env.E2E_BASE_URL});try{
      const vp=await viewer.newPage();await vp.goto('/login');await vp.getByLabel('Email').fill(viewerEmail);await vp.getByLabel('Пароль').fill(viewerPass);await vp.getByRole('button',{name:'Войти',exact:true}).click();await expect(vp).toHaveURL(/\/$/)
      await vp.goto(`/connectors?workspace_id=${ws}&target_id=${target}`);await expect(vp.getByTestId('connector-lifecycle')).toBeVisible();await expect(vp.getByRole('button',{name:'Поставить в очередь'})).toHaveCount(0);await expect(vp.getByRole('button',{name:'Сохранить настройки'})).toHaveCount(0)
      const forbidden=await vp.request.patch(api+path(),{headers:{'X-CSRF-Token':await csrf(vp.request),'If-Match':(await get<{etag:string}>(r,path())).etag},data:{name:'forbidden'}});expect(forbidden.status()).toBe(403)
      const other=await vp.request.get(api+`/connectors/targets?workspace_id=${owner!.id}`);expect([403,404]).toContain(other.status())
    }finally{await viewer.close()}
    // Switch workspace; target history must not follow across scopes.
    await page.getByLabel('Workspace',{exact:true}).selectOption(owner!.id);await expect(lifecycle).toHaveCount(0);await expect(page.getByText(label+'-edited',{exact:true})).toHaveCount(0)
  }finally{
    // Best-effort security cleanup; the workflow requires disposal of the staging database after the suite.
    if(viewerId)await r.patch(api+`/security/users/${viewerId}`,{headers:{'X-CSRF-Token':await csrf(r)},data:{active:false}});
    if(target){if(source)await r.delete(api+path()+`/webhook-sources/${source}`,{headers:{'X-CSRF-Token':await csrf(r)}});if(grant)await r.delete(api+path()+`/service-grants/${grant}`,{headers:{'X-CSRF-Token':await csrf(r)}})}
  }
})
