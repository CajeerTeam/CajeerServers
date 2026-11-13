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

test('Proxmox admin: real collectors, focused guest snapshot/console, PBS and workspace/role isolation',async({page,browser})=>{
  test.skip(process.env.E2E_CONNECTOR_UI!=='1','Disposable staging with the supplied combined HTTPS PVE/PBS fixture is required')
  test.setTimeout(720_000)
  expect(process.env.E2E_ALLOW_WRITES).toBe('1');expect(process.env.E2E_ISOLATED_STAGING).toBe('1');expect(host).toBeTruthy();expect(pin).toMatch(/^[a-f0-9]{64}$/);expect(process.env.E2E_ADMIN_TOTP_SECRET).toBeTruthy()
  await login(page,process.env.E2E_ADMIN_EMAIL||'',password,true)
  const r=page.request,label=`pve-ui-${randomUUID().slice(0,8)}`,snap=`e2e_${randomUUID().replaceAll('-','').slice(0,8)}`
  const owner=(await get<{id:string;organization_id:string;role:string}[]>(r,'/workspaces')).find(w=>w.role==='owner')!;expect(owner).toBeTruthy()
  const ws=(await post<{id:string}>(r,'/workspaces',{organization_id:owner.organization_id,slug:label,name:label})).id
  const target=async(provider:'proxmox'|'pbs')=>{
    const asset=(await post<{data:{id:string}}>(r,'/inventory/assets',{workspace_id:ws,type:provider==='proxmox'?'cluster':'server',name:label+'-'+provider})).data.id
    const credential=(await post<{data:{id:string}}>(r,'/vault/credentials',{workspace_id:ws,name:label+'-'+provider,type:provider==='proxmox'?'proxmox_api':'api_token',secret:provider==='proxmox'?{token_id:'cajeer-e2e@pve!fixture',token_secret:'fixture-only-cajeer-token'}:'cajeer-e2e@pbs!fixture:fixture-only-pbs-token'})).data.id
    const t=(await post<{data:{id:string;etag:string}}>(r,'/connectors/targets',{workspace_id:ws,provider,name:label+'-'+provider,endpoint_host:host,endpoint_port:port,asset_id:asset,...(provider==='proxmox'?{proxmox_profile:{cluster:null,anchor_node:'cajeer-e2e-pve',anchor_id:null,primary_pin:pin,fallbacks:[]}}:{})})).data
    const path=`/connectors/targets/${t.id}`
    await post(r,path+'/credential-bindings',{credential_id:credential,purpose:'api',attach_to_asset:true},{'If-Match':t.etag})
    return {...t,path}
  }
  const pve=await target('proxmox'),pbs=await target('pbs')
  const grants:{path:string;id:string}[]=[];let viewer=''
  const url=(id:string,view:string)=>`/proxmox?workspace_id=${ws}&target_id=${id}&view=${view}`
  const lifecycle=page.getByTestId('connector-lifecycle'),view=async(v:string)=>{await page.getByTestId('pve-view-'+v).click()}
  async function grant(t:typeof pve,operation:'inspect'|'sync'){
    await page.goto(url(t.id,'plans'));await lifecycle.getByRole('button',{name:'Разрешения и расписания'}).click();await lifecycle.getByTestId('grant-operation').selectOption(operation);await lifecycle.getByTestId('grant-ttl').fill('1800');await mfa(page);await lifecycle.getByRole('button',{name:'Выдать разрешение'}).click();await expect(lifecycle.getByRole('status')).toContainText('Разрешение выдано');const g=(await get<{id:string;operation:string}[]>(r,t.path+'/service-grants')).find(x=>x.operation===operation)!;expect(g).toBeTruthy();grants.push({path:t.path,id:g.id});return g.id
  }
  async function collect(t:typeof pve,g:string,collector:string){
    const run=(await post<{data:{id:string}}>(r,t.path+'/runs',{grant_id:g,collector},{'Idempotency-Key':randomUUID()})).data.id
    await expect.poll(async()=> (await get<{status:string;error_code:string|null}>(r,t.path+'/runs/'+run)).status,{timeout:120000,intervals:[1000,2000,5000]}).toBe('succeeded')
  }
  try{
    const sync=await grant(pve,'sync'),inspect=await grant(pve,'inspect')
    await collect(pve,sync,'proxmox.guests');await collect(pve,inspect,'proxmox.cluster');await collect(pve,inspect,'proxmox.storage-network')
    await page.goto(url(pve.id,'overview'));await expect(page.getByTestId('proxmox-cluster-discovery')).toContainText('cajeer-e2e-pve');await expect(page.getByTestId('proxmox-guest-discovery')).toHaveCount(0)
    await view('guests');const guests=page.getByTestId('proxmox-guest-discovery');await expect(guests).toContainText('cajeer-e2e-vm');await guests.getByPlaceholder('VMID, имя, узел, pool').fill('no-match');await expect(guests.getByTestId('pve-manage-qemu:101')).toHaveCount(0);await guests.getByPlaceholder('VMID, имя, узел, pool').fill('101');await guests.getByTestId('pve-manage-qemu:101').click();await expect(page.getByTestId('pve-guest-context')).toContainText('qemu:101');await expect(lifecycle.getByTestId('power-resource')).toHaveValue('qemu:101')
    // Never click the external console link. Verify the returned native URL without credentials.
    await mfa(page);await lifecycle.getByRole('button',{name:'Получить ссылку на консоль'}).click();const consoleLink=lifecycle.getByRole('link',{name:'Открыть нативную консоль Proxmox'});await expect(consoleLink).toBeVisible();const consoleUrl=new URL((await consoleLink.getAttribute('href'))!);expect(consoleUrl.protocol).toBe('https:');expect(consoleUrl.searchParams.get('console')).toBe('kvm');expect(consoleUrl.searchParams.get('vmid')).toBe('101');expect(consoleUrl.username).toBe('');expect(consoleUrl.password).toBe('');expect([...consoleUrl.searchParams.keys()].sort()).toEqual(['console','node','novnc','vmid'])
    // Only fixture VM 101 and synthetic snapshot names are eligible; backend checks the pinned TLS identity.
    await lifecycle.getByLabel('Действие',{exact:true}).selectOption('vm.snapshot.create');await lifecycle.getByLabel('Имя нового снимка').fill(snap);await mfa(page);await lifecycle.getByTestId('power-confirm').fill('wrong');await expect(lifecycle.getByRole('button',{name:'Выполнить действие'})).toBeDisabled();await lifecycle.getByTestId('power-confirm').fill(`vm.snapshot.create qemu:101 ${snap}`);await lifecycle.getByRole('button',{name:'Выполнить действие'}).click();await expect(lifecycle.getByTestId('action-detail')).toContainText('submitted');await mfa(page);await lifecycle.getByRole('button',{name:'Проверить удалённую задачу'}).click();await expect(lifecycle.getByTestId('action-detail')).toContainText('succeeded')
    await mfa(page);await lifecycle.getByRole('button',{name:'Загрузить снимки'}).click();await expect(lifecycle.getByRole('cell',{name:snap,exact:true})).toBeVisible();await lifecycle.getByLabel('Действие',{exact:true}).selectOption('vm.snapshot.delete');await lifecycle.getByLabel('Снимок',{exact:true}).selectOption(snap);await mfa(page);await lifecycle.getByTestId('power-confirm').fill(`vm.snapshot.delete qemu:101 ${snap}`);await lifecycle.getByRole('button',{name:'Выполнить действие'}).click();await expect(lifecycle.getByTestId('action-detail')).toContainText('submitted');await mfa(page);await lifecycle.getByRole('button',{name:'Проверить удалённую задачу'}).click();await expect(lifecycle.getByTestId('action-detail')).toContainText('succeeded')
    await view('storage');await expect(page.getByTestId('proxmox-storage-network-discovery')).toContainText('local-lvm:vm-101-disk-0');await expect(page.getByTestId('proxmox-storage-network-discovery')).toContainText('vmbr0');await expect(consoleLink).toHaveCount(0)
    await collect(pve,inspect,'proxmox.tasks');await view('tasks');await expect(page.getByTestId('proxmox-task-history')).toContainText('qmsnapshot');await page.getByTestId('proxmox-task-history').getByLabel('Представление').selectOption('events');await expect(page.getByTestId('proxmox-task-history')).toContainText('Обнаружена')
    const pbsGrant=await grant(pbs,'inspect');await collect(pbs,pbsGrant,'pbs.backups');await view('backups');const backups=page.getByTestId('pbs-backup-discovery');await expect(backups).toContainText('e2e-store');await expect(backups).toContainText('vm/101');await backups.getByLabel('Раздел').selectOption('jobs');await expect(backups).toContainText('e2e-job');await expect(page.getByTestId('pve-view-actions')).toHaveCount(0)
    // URL, reload, back and scope changes cannot retain another target's data.
    await page.goto(url(pve.id,'guests'));await expect(guests).toContainText('cajeer-e2e-vm');await page.reload();await expect(guests).toContainText('cajeer-e2e-vm');await view('storage');await page.goBack();await expect(guests).toContainText('cajeer-e2e-vm');await page.goto(`/proxmox?workspace_id=${owner.id}&target_id=${pve.id}&view=guests`);await expect(page.getByRole('alert')).toContainText('не относится');await expect(guests).toHaveCount(0)
    const email=`${label}@example.invalid`,pass=`fixture-${randomUUID()}-only`,slug=label+'-viewer'
    viewer=(await post<{id:string}>(r,'/security/users',{email,name:label,password:pass})).id
    await post(r,'/security/roles',{slug,name:slug,permissions:['workspaces.read','connectors.targets.read','inventory.assets.read']})
    expect((await r.post(api+`/security/users/${viewer}/roles/${slug}`,{headers:{'X-CSRF-Token':await csrf(r)}})).ok()).toBe(true)
    expect((await r.put(api+`/workspaces/${ws}/members/${viewer}`,{headers:{'X-CSRF-Token':await csrf(r)},data:{role:'viewer'}})).status()).toBe(204)
    const context=await browser.newContext({baseURL:process.env.E2E_BASE_URL});try{const v=await context.newPage();await login(v,email,pass,false);await v.goto(url(pve.id,'actions'));await expect(v.getByTestId('proxmox-cluster-discovery')).toContainText('cajeer-e2e-pve');await expect(v.getByTestId('pve-view-actions')).toHaveCount(0);await expect(v.getByTestId('power-resource')).toHaveCount(0);const denied=await v.request.post(api+pve.path+'/actions',{headers:{'X-CSRF-Token':await csrf(v.request),'If-Match':(await get<{etag:string}>(r,pve.path)).etag,'Idempotency-Key':randomUUID()},data:{action:'vm.start',external_id:'qemu:101',confirm:'vm.start qemu:101'}});expect(denied.status()).toBe(403)}finally{await context.close()}
  }finally{
    for(const g of grants)await r.delete(api+g.path+'/service-grants/'+g.id,{headers:{'X-CSRF-Token':await csrf(r)}})
    if(viewer)await r.patch(api+`/security/users/${viewer}`,{headers:{'X-CSRF-Token':await csrf(r)},data:{active:false}})
  }
})
