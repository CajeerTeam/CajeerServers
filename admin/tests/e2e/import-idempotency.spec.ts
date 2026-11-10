import {test,expect} from '@playwright/test'

/** A true lost-response exercise against PostgreSQL, not an in-memory HTTP mock. */
const email=process.env.E2E_ADMIN_EMAIL||''
const password=process.env.E2E_ADMIN_PASSWORD||''
const writes=process.env.E2E_ALLOW_WRITES==='1'

test('retry after lost HTTP response reuses the durable import batch',async ({page})=>{
  test.skip(!email||!password||!writes,'Requires disposable PostgreSQL staging and E2E_ALLOW_WRITES=1')
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Пароль').fill(password)
  if(process.env.E2E_ADMIN_MFA)await page.getByLabel(/Код TOTP/).fill(process.env.E2E_ADMIN_MFA)
  await page.getByRole('button',{name:'Войти',exact:true}).click()
  await expect(page).toHaveURL(/\/$/)
  await page.getByRole('link',{name:'Импорт / Экспорт'}).click()
  await expect(page.getByRole('heading',{name:'Импорт, экспорт и привязки credentials'})).toBeVisible()
  const key=`e2e-retry-${Date.now().toString(36)}`
  const csv=`external_id,type,name,status,os_family,environment,ip_addresses,tags\n${key},server,Import Retry Test,online,ubuntu,staging,[],[]\n`
  await page.locator('#transfer-file').setInputFiles({name:'test.csv',mimeType:'text/csv',buffer:Buffer.from(csv)})
  await page.getByRole('button',{name:'Предпросмотр'}).click()
  await expect(page.getByText(key)).toBeVisible()
  let originalId=''
  const headers:string[]=[]
  let first=true
  await page.route('**/api/v1/inventory/imports',async route=>{
    if(route.request().method()!=='POST'){await route.continue();return}
    headers.push(route.request().headers()['idempotency-key']||'')
    if(!first){await route.continue();return}
    first=false
    const response=await route.fetch()
    expect(response.ok(),'First import batch committed server-side').toBe(true)
    const data=(await response.json()).data as {id:string}
    originalId=data.id
    // Simulate response loss *after* PostgreSQL commits the first batch.
    await route.fulfill({status:502,contentType:'application/json',body:JSON.stringify({title:'Temporary upstream failure'})})
  })
  await page.getByRole('button',{name:'Отправить в очередь'}).click()
  await expect(page.getByRole('alert').first()).toBeVisible()
  await page.getByRole('button',{name:'Отправить в очередь'}).click()
  await expect(page.getByRole('heading',{name:`Импорт ${originalId}`})).toBeVisible()
  expect(headers).toHaveLength(2)
  expect(headers[0]).toMatch(/^[0-9a-f-]{36}$/i)
  expect(headers[1]).toBe(headers[0])
  await page.getByRole('button',{name:'Обновить результат'}).click()
  await expect(page.getByRole('status').last()).toContainText(/Статус:/)
})
