import {test,expect,type APIRequestContext,type Page} from '@playwright/test'
import {randomUUID} from 'node:crypto'
import {freshAdminTotp} from './admin-totp'
const api='/api/v1',password=process.env.E2E_ADMIN_PASSWORD||''
const host=process.env.E2E_CONNECTOR_FIXTURE_HOST||'',port=Number(process.env.E2E_CONNECTOR_FIXTURE_PORT||8443),pin=process.env.E2E_CONNECTOR_FIXTURE_PIN||''
test.use({trace:'off',screenshot:'off',video:'off'})
async function csrf(r:APIRequestContext){const res=await r.get(api+'/auth/csrf');expect(res.ok()).toBe(true);return (await res.json()).csrf_token as string}
async function post<T>(r:APIRequestContext,path:string,data:unknown,headers:Record<string,string>={}):Promise<T>{const res=await r.post(api+path,{data,headers:{'X-CSRF-Token':await csrf(r),...headers}});expect(res.ok(),`POST ${path}: ${res.status()}`).toBe(true);return await res.json() as T}
async function get<T>(r:APIRequestContext,path:string):Promise<T>{const res=await r.get(api+path);expect(res.ok(),`GET ${path}: ${res.status()}`).toBe(true);return (await res.json()).data as T}
async function login(page:Page,email:string,pass:string,admin:boolean){await page.goto('/login');await page.getByLabel('Email').fill(email);await page.getByLabel('Пароль').fill(pass);if(admin)await page.getByLabel(/Код TOTP/).fill(await freshAdminTotp());await page.getByRole('button',{name:'Войти',exact:true}).click();await expect(page).toHaveURL(/\/$/)}
async function mfa(page:Page){const l=page.getByTestId('connector-lifecycle');await l.getByTestId('lifecycle-password').fill(password);await l.getByTestId('lifecycle-totp').fill(await freshAdminTotp())}

test('VMmanager admin: real sync dashboard, focused guest action, history and workspace isolation',async({page,browser})=>{
  test.skip(process.env.E2E_CONNECTOR_UI!=='1','Disposable staging with combined HTTPS fixture required')
  test.setTimeout(720_000)
  expect(process.env.E2E_ALLOW_WRITES).toBe('1');expect(process.env.E2E_ISOLATED_STAGING).toBe('1');expect(host).toBeTruthy();expect(pin).toMatch(/^[a-f0-9]{64}$/)
  await login(page,process.env.E2E_ADMIN_EMAIL||'',password,true)
  const r=page.request,label=`vmm-ui-${randomUUID().slice(0,8)}`
  const owner=(await get<{id:string;organization_id:string;role:string}[]>(r,'/workspaces')).find(w=>w.role==='owner')!;expect(owner).toBeTruthy()
  const ws=(await post<{id:string}>(r,'/workspaces',{organization_id:owner.organization_id,slug:label,name:label})).id
  const asset=(await post<{data:{id:string}}>(r,'/inventory/assets',{workspace_id:ws,type:'cluster',name:label})).data.id
  const credential=(await post<{data:{id:string}}>(r,'/vault/credentials',{workspace_id:ws,name:label,type:'vmmanager_api',secret:{token:'fixture-vmm-e2e-'+randomUUID().replaceAll('-','')}})).data.id
  const t=(await post<{data:{id:string;etag:string}}>(r,'/connectors/targets',{workspace_id:ws,provider:'vmmanager',name:label,endpoint_host:host,endpoint_port:port,asset_id:asset,vmmanager_profile:{anchor_id:1,anchor_node:'cajeer-e2e-vmm-node-1',primary_pin:pin}})).data
  const path=`/connectors/targets/${t.id}`
  await post(r,path+'/credential-bindings',{credential_id:credential,purpose:'api',attach_to_asset:true},{'If-Match':t.etag})
  const url=(view:string,guest='')=>`/vmmanager?workspace_id=${ws}&target_id=${t.id}&view=${view}${guest?'&guest='+encodeURIComponent(guest):''}`
  const lifecycle=page.getByTestId('connector-lifecycle'),dashboard=page.getByTestId('vmmanager-sync-dashboard'),guests=page.getByTestId('vmmanager-guests-discovery')
  const grants:string[]=[];let viewer=''
  async function grant(operation:'inspect'|'sync'){
    await page.goto(url('plans'));await lifecycle.getByRole('button',{name:'Разрешения и расписания'}).click();await lifecycle.getByTestId('grant-operation').selectOption(operation);await lifecycle.getByTestId('grant-ttl').fill('1800');await mfa(page);await lifecycle.getByRole('button',{name:'Выдать разрешение'}).click();await expect(lifecycle.getByRole('status')).toContainText('Разрешение выдано');const g=(await get<{id:string;operation:string}[]>(r,path+'/service-grants')).find(x=>x.operation===operation)!;expect(g).toBeTruthy();grants.push(g.id);return g.id
  }
  async function collect(g:string,collector:string){
    const id=(await post<{data:{id:string}}>(r,path+'/runs',{grant_id:g,collector},{'Idempotency-Key':randomUUID()})).data.id
    await expect.poll(async()=> (await get<{status:string}>(r,path+'/runs/'+id)).status,{timeout:120000,intervals:[1000,2000,5000]}).toBe('succeeded');return id
  }
  try{
    await page.goto(url('overview'));await expect(dashboard).toBeVisible();await expect(dashboard.getByTestId('vmm-sync-vmmanager.guests')).toContainText('Не собран')
    const sync=await grant('sync'),inspect=await grant('inspect')
    // A task plan is required by the action dispatcher; create through real UI and MFA.
    await page.goto(url('plans'));await lifecycle.getByLabel('Название',{exact:true}).fill(label+'-tasks');await lifecycle.getByLabel('Коллектор',{exact:true}).selectOption('vmmanager.tasks');await mfa(page);await lifecycle.getByRole('button',{name:'Создать план'}).click();await expect(lifecycle.getByRole('status')).toContainText('План создан')
    const plans=await get<{id:string;grant_id:string;collector_name:string}[]>(r,path+'/collection-plans');const taskPlan=plans.find(p=>p.collector_name==='vmmanager.tasks')!;expect(taskPlan).toBeTruthy();grants.push(taskPlan.grant_id)
    await collect(sync,'vmmanager.guests');for(const c of ['cluster','network','storage','templates','tasks'])await collect(inspect,'vmmanager.'+c)
    await page.goto(url('overview'));await expect(dashboard.getByTestId('vmm-sync-vmmanager.guests')).toContainText('Свежий');await expect(dashboard.getByTestId('vmm-sync-vmmanager.guests')).toContainText('succeeded');await expect(dashboard.getByTestId('vmm-sync-vmmanager.tasks')).toContainText('Курсор:');await expect(dashboard).toContainText(label+'-tasks')
    await page.getByTestId('vmm-view-guests').click();await expect(guests).toContainText('cajeer-e2e-vmm-21');await guests.getByPlaceholder('Имя, ID, узел, ОС или IP').fill('no-match');await expect(guests.getByTestId('vmm-manage-host:21')).toHaveCount(0);await guests.getByPlaceholder('Имя, ID, узел, ОС или IP').fill('21');await guests.getByTestId('vmm-manage-host:21').click();await expect(page.getByTestId('vmm-guest-context')).toContainText('host:21');await expect(lifecycle.getByTestId('power-resource')).toHaveValue('host:21');await expect(lifecycle.getByTestId('power-resource')).toBeDisabled()
    await mfa(page);await lifecycle.getByTestId('power-confirm').fill('wrong');await expect(lifecycle.getByRole('button',{name:'Выполнить действие'})).toBeDisabled();await lifecycle.getByTestId('power-confirm').fill('vmmanager.host.start host:21');await lifecycle.getByRole('button',{name:'Выполнить действие'}).click();await expect(lifecycle.getByTestId('action-detail')).toContainText('submitted')
    await collect(inspect,'vmmanager.tasks');await expect(lifecycle.getByTestId('action-detail')).toContainText('succeeded',{timeout:30000})
    await page.getByTestId('vmm-view-tasks').click();await expect(page.getByTestId('vmmanager-task-history')).toContainText('host_start');await page.getByTestId('vmmanager-task-history').getByLabel('Представление').selectOption('events');await expect(page.getByTestId('vmmanager-task-history')).toContainText('Обнаружена')
    for(const [v,testId,text] of [['cluster','vmmanager-cluster-discovery','E2E KVM'],['network','vmmanager-network-discovery','192.0.2.0/24'],['storage','vmmanager-storage-discovery','E2E storage'],['templates','vmmanager-templates-discovery','E2E Linux']]){await page.getByTestId('vmm-view-'+v).click();await expect(page.getByTestId(testId)).toContainText(text)}
    await page.goto(url('guests'));await expect(guests).toContainText('cajeer-e2e-vmm-21');await page.reload();await expect(guests).toContainText('cajeer-e2e-vmm-21');await page.getByTestId('vmm-view-storage').click();await page.goBack();await expect(guests).toBeVisible();await page.goto(url('actions','host:0'));await expect(page.getByRole('alert')).toContainText('Некорректный идентификатор гостя');await expect(lifecycle).toHaveCount(0)
    await page.goto(`/vmmanager?workspace_id=${owner.id}&target_id=${t.id}&view=guests`);await expect(page.getByRole('alert')).toContainText('не относится');await expect(guests).toHaveCount(0)
    const email=`${label}@example.invalid`,pass=`fixture-${randomUUID()}-only`,slug=label+'-viewer'
    viewer=(await post<{id:string}>(r,'/security/users',{email,name:label,password:pass})).id
    await post(r,'/security/roles',{slug,name:slug,permissions:['workspaces.read','connectors.targets.read','inventory.assets.read']});expect((await r.post(api+`/security/users/${viewer}/roles/${slug}`,{headers:{'X-CSRF-Token':await csrf(r)}})).ok()).toBe(true);expect((await r.put(api+`/workspaces/${ws}/members/${viewer}`,{headers:{'X-CSRF-Token':await csrf(r)},data:{role:'viewer'}})).status()).toBe(204)
    const context=await browser.newContext({baseURL:process.env.E2E_BASE_URL});try{const v=await context.newPage();await login(v,email,pass,false);await v.goto(url('actions'));await expect(v.getByTestId('vmmanager-sync-dashboard')).toBeVisible();await expect(v.getByTestId('vmm-view-actions')).toHaveCount(0);await expect(v.getByTestId('vmm-view-network')).toHaveCount(0);const denied=await v.request.post(api+path+'/actions',{headers:{'X-CSRF-Token':await csrf(v.request),'If-Match':(await get<{etag:string}>(r,path)).etag,'Idempotency-Key':randomUUID()},data:{action:'vmmanager.host.stop',external_id:'host:21',confirm:'vmmanager.host.stop host:21'}});expect(denied.status()).toBe(403)}finally{await context.close()}
  }finally{for(const g of grants)await r.delete(api+path+'/service-grants/'+g,{headers:{'X-CSRF-Token':await csrf(r)}});if(viewer)await r.patch(api+`/security/users/${viewer}`,{headers:{'X-CSRF-Token':await csrf(r)},data:{active:false}})}
})
