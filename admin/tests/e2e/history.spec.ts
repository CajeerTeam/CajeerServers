import {freshAdminTotp} from './admin-totp'
import { test, expect } from '@playwright/test'
/** Requires disposable staging Inventory; creates persistent import observations and revisions. */
const email=process.env.E2E_ADMIN_EMAIL||''
const password=process.env.E2E_ADMIN_PASSWORD||''
test('CMDB history, idempotent observations and manual override conflicts',async ({page})=>{
  test.skip(!email||!password||process.env.E2E_HISTORY_WRITABLE!=='1','Requires disposable staging with E2E_HISTORY_WRITABLE=1')
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Пароль').fill(password)
  {const code=await freshAdminTotp();if(code)await page.getByLabel(/Код TOTP/).fill(code)}
  await page.getByRole('button',{name:'Войти',exact:true}).click()
  await expect(page).toHaveURL(/\/$/)
  await page.getByRole('link',{name:'История CMDB'}).click()
  await expect(page.getByRole('heading',{name:'История и происхождение данных CMDB'})).toBeVisible()
  const key=`e2e-history-${Date.now()}`
  await page.locator('#obs-id').fill(key)
  await page.locator('#obs-key').fill(`${key}-1`)
  await page.locator('#obs-name').fill('History E2E Node')
  await page.getByRole('button',{name:'Сохранить наблюдение'}).click()
  await expect(page.getByRole('status')).toContainText('created')
  await page.getByRole('button',{name:'Сохранить наблюдение'}).click()
  await expect(page.getByRole('status')).toContainText('(повтор)')
  const option=page.locator('#history-asset option').filter({hasText:'History E2E Node'})
  await expect(option).toHaveCount(1)
  const value=await option.getAttribute('value')
  if(!value)throw new Error('New imported asset missing')
  await page.locator('#history-asset').selectOption(value)
  await expect(page.getByRole('heading',{name:'История версий'})).toBeVisible()
  await expect(page.getByRole('heading',{name:'Provenance полей'})).toBeVisible()
  await page.locator('#override-field').selectOption('name')
  await page.locator('#override-value').fill('Human override')
  await page.getByRole('button',{name:'Сохранить override'}).click()
  await expect(page.getByRole('status')).toContainText('Ручное значение сохранено')
  await page.locator('#obs-key').fill(`${key}-2`)
  await page.locator('#obs-name').fill('Observed conflicting name')
  await page.getByRole('button',{name:'Сохранить наблюдение'}).click()
  await expect(page.getByRole('status')).toContainText('conflicted')
  await expect(page.getByText('Ручное переопределение')).toBeVisible()
  await expect(page.getByText(/name: наблюдалось/)).toBeVisible()
})
