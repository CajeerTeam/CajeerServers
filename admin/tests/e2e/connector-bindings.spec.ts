import {test,expect} from '@playwright/test'

/** Actual browser -> Nuxt -> PHP -> PostgreSQL test; writes ONLY to disposable staging. */
const email=process.env.E2E_ADMIN_EMAIL||''
const password=process.env.E2E_ADMIN_PASSWORD||''
const allowed=process.env.E2E_ALLOW_WRITES==='1' && process.env.E2E_ISOLATED_STAGING==='1'
  && process.env.E2E_VAULT_BINDINGS==='1'

test('Vault credential -> connector target binding -> disable -> unbind -> cleanup',async ({page})=>{
  test.skip(!allowed||!email||!password,'Requires explicitly writable isolated staging with Vault initialized')
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Пароль').fill(password)
  if(process.env.E2E_ADMIN_MFA)await page.getByLabel(/Код TOTP/).fill(process.env.E2E_ADMIN_MFA)
  await page.getByRole('button',{name:'Войти',exact:true}).click()
  await expect(page).toHaveURL(/\/$/)
  const csrf=async ()=>{
    const res=await page.request.get('/api/v1/auth/csrf')
    expect(res.ok()).toBe(true)
    return (await res.json()).csrf_token as string
  }
  const res=await page.request.get('/api/v1/workspaces')
  expect(res.ok()).toBe(true)
  const spaces=(await res.json()).data as {id:string;role:string}[]
  const ws=spaces.find(w=>['owner','manager'].includes(w.role))?.id
  expect(ws).toBeTruthy()
  let credentialId='',targetId=''
  const label=`e2e-connector-${Date.now().toString(36)}`
  try {
    const cred=await page.request.post('/api/v1/vault/credentials',{
      headers:{'X-CSRF-Token':await csrf()},
      data:{workspace_id:ws,name:label,type:'api_token',secret:`test-only-${label}-not-for-production`},
    })
    expect(cred.status(),await cred.text()).toBe(201)
    credentialId=(await cred.json()).data.id
    await page.goto(`/connectors?workspace_id=${ws}`)
    await expect(page.getByRole('heading',{name:'Конфигурации подключений и Vault'})).toBeVisible()
    const form=page.locator('section.panel').filter({has:page.getByRole('heading',{name:'Новая конфигурация'})})
    await form.locator('input').first().fill(label)
    await form.locator('select').selectOption('proxmox')
    await form.locator('input').nth(1).fill('pve-e2e.internal')
    await form.getByRole('button',{name:'Создать конфигурацию'}).click()
    await expect(page.getByRole('status')).toContainText('Конфигурация сохранена')
    const targets=await page.request.get(`/api/v1/connectors/targets?workspace_id=${ws}`)
    expect(targets.ok()).toBe(true)
    const target=((await targets.json()).data as {id:string;name:string}[]).find(t=>t.name===label)
    expect(target).toBeTruthy();targetId=target!.id
    const targetGet=await page.request.get(`/api/v1/connectors/targets/${targetId}`)
    const current=(await targetGet.json()).data as {etag:string}
    const stale=await page.request.patch(`/api/v1/connectors/targets/${targetId}`,{
      headers:{'X-CSRF-Token':await csrf(),'If-Match':`"${targetId}:999"`},data:{name:'must-not-apply'},
    })
    expect(stale.status()).toBe(412)
    await page.locator('section.panel').filter({has:page.getByRole('heading',{name:'Конфигурации',exact:true})})
      .locator('tr').filter({hasText:label}).getByRole('button',{name:'Привязки'}).click()
    await expect(page.getByRole('heading',{name:`Credentials: ${label}`})).toBeVisible()
    const bindForm=page.locator('form').filter({has:page.getByRole('button',{name:'Привязать credential'})})
    await bindForm.locator('select').first().selectOption(credentialId)
    await bindForm.getByRole('button',{name:'Привязать credential'}).click()
    await expect(page.getByRole('status')).toContainText('Credential привязан')
    await expect(page.getByText(credentialId,{exact:false})).toBeVisible()
    const archived=await page.request.delete(`/api/v1/vault/credentials/${credentialId}`,{
      headers:{'X-CSRF-Token':await csrf(),'If-Match':(await (await page.request.get(`/api/v1/vault/credentials/${credentialId}`)).json()).data.etag},
    })
    expect(archived.status()).toBe(409)
    await page.getByRole('button',{name:'Отвязать'}).click()
    await expect(page.getByRole('status')).toContainText('Привязка удалена')
  } finally {
    if(targetId){
      const current=await page.request.get(`/api/v1/connectors/targets/${targetId}`)
      if(current.ok()){
        const info=(await current.json()).data as {etag:string}
        const bindings=await page.request.get(`/api/v1/connectors/targets/${targetId}/credential-bindings`)
        if(bindings.ok())for(const b of (await bindings.json()).data as {id:string}[]){
          const latest=(await (await page.request.get(`/api/v1/connectors/targets/${targetId}`)).json()).data as {etag:string}
          await page.request.delete(`/api/v1/connectors/targets/${targetId}/credential-bindings/${b.id}`,{
            headers:{'X-CSRF-Token':await csrf(),'If-Match':latest.etag},
          })
        }
        const latest=(await (await page.request.get(`/api/v1/connectors/targets/${targetId}`)).json()).data as {etag:string}
        const removed=await page.request.delete(`/api/v1/connectors/targets/${targetId}`,{
          headers:{'X-CSRF-Token':await csrf(),'If-Match':latest.etag},
        })
        expect(removed.status()).toBe(204)
      }
    }
    if(credentialId){
      const current=await page.request.get(`/api/v1/vault/credentials/${credentialId}`)
      if(current.ok()){
        const info=(await current.json()).data as {etag:string}
        const removed=await page.request.delete(`/api/v1/vault/credentials/${credentialId}`,{
          headers:{'X-CSRF-Token':await csrf(),'If-Match':info.etag},
        })
        expect(removed.ok()).toBe(true)
      }
    }
  }
})
