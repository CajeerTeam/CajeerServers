import {freshAdminTotp} from './admin-totp'
import {test,expect} from '@playwright/test'

/** Requires a disposable staging CMDB, PostgreSQL and a running queue worker. */
const email=process.env.E2E_ADMIN_EMAIL||''
const password=process.env.E2E_ADMIN_PASSWORD||''
test('CSV import preview, queued reconciliation and safe export',async ({page})=>{
  test.skip(!email||!password||process.env.E2E_TRANSFER_WRITABLE!=='1','Requires disposable staging and active worker')
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Пароль').fill(password)
  {const code=await freshAdminTotp();if(code)await page.getByLabel(/Код TOTP/).fill(code)}
  await page.getByRole('button',{name:'Войти',exact:true}).click()
  await expect(page).toHaveURL(/\/$/)
  await page.getByRole('link',{name:'Импорт / Экспорт'}).click()
  await expect(page.getByRole('heading',{name:'Импорт, экспорт и привязки credentials'})).toBeVisible()
  const identifier=`e2e-transfer-${Date.now()}`
  const csv=`external_id,type,name,status,os_family,environment,ip_addresses,tags\n${identifier},server,Transfer E2E Server,online,ubuntu,staging,\"[\"\"192.0.2.187\"\"]\",\"[\"\"e2e\"\"]\"\n`
  await page.locator('#transfer-file').setInputFiles({name:'inventory.csv',mimeType:'text/csv',buffer:Buffer.from(csv)})
  await page.getByRole('button',{name:'Предпросмотр'}).click()
  await expect(page.getByText(identifier)).toBeVisible()
  await expect(page.getByText('create',{exact:true})).toBeVisible()
  await page.getByRole('button',{name:'Отправить в очередь'}).click()
  await expect(page.getByRole('heading',{name:/Импорт [0-9a-f-]{36}/})).toBeVisible()
  await expect.poll(async ()=>{
    await page.getByRole('button',{name:'Обновить результат'}).click()
    return await page.locator('.panel').filter({has:page.getByRole('button',{name:'Обновить результат'})}).innerText()
  },{timeout:30000}).toMatch(/completed/)
  const [download]=await Promise.all([page.waitForEvent('download'),page.getByRole('button',{name:'Экспорт CSV'}).click()])
  expect(download.suggestedFilename()).toBe('cajeer-inventory.csv')
})
