import {freshAdminTotp} from './admin-totp'
import { test, expect } from '@playwright/test'

/** Real Nuxt + PHP + PostgreSQL deployment, disposable staging only. */
const email = process.env.E2E_ADMIN_EMAIL || ''
const password = process.env.E2E_ADMIN_PASSWORD || ''
const writes = process.env.E2E_ALLOW_WRITES === '1'

test('Inventory card → history → IPAM → topology → transfer, with live ETag edit', async ({page}) => {
  test.skip(!email || !password || !writes, 'Requires isolated staging, E2E_ADMIN_* and E2E_ALLOW_WRITES=1')
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Пароль').fill(password)
  {const code=await freshAdminTotp();if(code)await page.getByLabel(/Код TOTP/).fill(code)}
  await page.getByRole('button',{name:'Войти',exact:true}).click()
  await expect(page).toHaveURL(/\/$/)
  const workspaceResponse = await page.request.get('/api/v1/workspaces')
  expect(workspaceResponse.ok()).toBe(true)
  const spaces=(await workspaceResponse.json()).data as {id:string;role:string}[]
  const ws=spaces.find(s=>['owner','manager'].includes(s.role))?.id
  expect(ws,'Writable workspace is required').toBeTruthy()
  const title=`E2E-journey-${Date.now().toString(36)}`
  let assetId=''
  const csrf = async () => {
    const response=await page.request.get('/api/v1/auth/csrf')
    expect(response.ok()).toBe(true)
    return (await response.json()).csrf_token as string
  }
  try {
    await page.goto(`/inventory?workspace_id=${ws}`)
    await expect(page.locator('#inventory-workspace')).toHaveValue(ws!)
    await page.locator('#inv-name').fill(title)
    await page.getByRole('button',{name:'Сохранить',exact:true}).click()
    await expect(page.getByRole('status')).toContainText('Объект создан')
    const row=page.locator('tr').filter({hasText:title})
    await expect(row).toBeVisible()
    await row.getByRole('link',{name:title}).click()
    await expect(page.getByRole('heading',{name:'Карточка инфраструктурного объекта'})).toBeVisible()
    assetId=new URL(page.url()).pathname.split('/').pop()||''
    expect(assetId).toMatch(/^[0-9a-f-]{36}$/)
    await expect(page.getByRole('heading',{name:title})).toBeVisible()
    await expect(page.getByText('Workspace',{exact:true})).toBeVisible()
    await page.locator('#detail-name').fill(`${title}-edited`)
    await page.locator('#detail-status').selectOption('online')
    await page.getByRole('button',{name:'Сохранить изменения'}).click()
    await expect(page.getByRole('status')).toContainText('Изменения сохранены')
    await expect(page.getByRole('heading',{name:`${title}-edited`})).toBeVisible()

    // The backend must reject an old, forged ETag even for an authorized session.
    const stale=await page.request.patch(`/api/v1/inventory/assets/${assetId}`,{
      headers:{'X-CSRF-Token':await csrf(),'If-Match':`"${assetId}:1"`},data:{name:'must-not-overwrite'},
    })
    expect(stale.status()).toBe(412)

    await page.getByRole('link',{name:'История и provenance'}).click()
    await expect(page.locator('#history-space')).toHaveValue(ws!)
    await expect(page.locator('#history-asset')).toHaveValue(assetId)
    await expect(page.getByRole('heading',{name:'История версий'})).toBeVisible()
    await page.getByRole('link',{name:'Карточка объекта'}).click()

    await page.getByRole('link',{name:'Сети и IPAM'}).last().click()
    await expect(page.locator('#ipam-workspace')).toHaveValue(ws!)
    await expect(page.locator('section.panel').filter({has:page.getByRole('heading',{name:/Сетевые интерфейсы/})}).locator('select').first()).toHaveValue(assetId)
    await page.goto(`/inventory/${assetId}?workspace_id=${ws}`)
    await page.getByRole('link',{name:'Топология'}).click()
    await expect(page.locator('#topology-workspace')).toHaveValue(ws!)
    await expect(page.locator('#topo-source')).toHaveValue(assetId)
    await page.goto(`/inventory/${assetId}?workspace_id=${ws}`)
    await page.getByRole('link',{name:'Импорт и credentials'}).click()
    await expect(page.locator('#transfer-workspace')).toHaveValue(ws!)
    if(await page.locator('#credential-asset').count())await expect(page.locator('#credential-asset')).toHaveValue(assetId)
  } finally {
    if(assetId){
      const current=await page.request.get(`/api/v1/inventory/assets/${assetId}`)
      if(current.ok()){
        const a=(await current.json()).data as {etag:string}
        const result=await page.request.delete(`/api/v1/inventory/assets/${assetId}`,{
          headers:{'X-CSRF-Token':await csrf(),'If-Match':a.etag},
        })
        expect(result.ok(),'Staging test object cleanup').toBe(true)
      }
    }
  }
})
