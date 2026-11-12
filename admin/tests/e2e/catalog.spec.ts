import {freshAdminTotp} from './admin-totp'
import { test, expect } from '@playwright/test'
/** Real staging instance only: uses unique catalog objects and removes them. */
const email=process.env.E2E_ADMIN_EMAIL||''
const password=process.env.E2E_ADMIN_PASSWORD||''
test('catalog tags and groups, authorized UI with ETag',async ({page})=>{
  test.skip(!email||!password,'Requires staging credentials')
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Пароль').fill(password)
  {const code=await freshAdminTotp();if(code)await page.getByLabel(/Код TOTP/).fill(code)}
  await page.getByRole('button',{name:'Войти',exact:true}).click()
  await expect(page).toHaveURL(/\/$/)
  await page.getByRole('link',{name:'Теги и группы'}).click()
  await expect(page.getByRole('heading',{name:'Теги и группы CMDB'})).toBeVisible()
  const slug=`e2e-${Date.now()}`
  await page.locator('#catalog-slug').fill(slug)
  await page.locator('#catalog-name').fill('E2E tag')
  await page.getByRole('button',{name:'Сохранить'}).click()
  await expect(page.getByRole('status')).toContainText('Запись создана')
  const tagRow=page.locator('tr').filter({hasText:slug})
  await expect(tagRow).toBeVisible()
  await tagRow.getByRole('button',{name:'Изменить'}).click()
  await page.locator('#catalog-name').fill('E2E tag edited')
  await page.getByRole('button',{name:'Сохранить'}).click()
  await expect(page.getByRole('status')).toContainText('Запись обновлена')
  await page.locator('#catalog-kind').selectOption('groups')
  await page.locator('#catalog-slug').fill(slug)
  await page.locator('#catalog-name').fill('E2E group')
  await page.getByRole('button',{name:'Сохранить'}).click()
  const groupRow=page.locator('tr').filter({hasText:slug}).filter({hasText:'E2E group'})
  await expect(groupRow).toBeVisible()
  await groupRow.getByRole('button',{name:'Состав'}).click()
  await expect(page.getByRole('heading',{name:'Состав группы «E2E group»'})).toBeVisible()
  page.once('dialog',d=>d.accept())
  await groupRow.getByRole('button',{name:'Удалить'}).click()
  await expect(page.getByRole('status')).toContainText('Запись удалена')
  page.once('dialog',d=>d.accept())
  await page.locator('tr').filter({hasText:'E2E tag edited'}).getByRole('button',{name:'Удалить'}).click()
  await expect(page.getByRole('status')).toContainText('Запись удалена')
})
