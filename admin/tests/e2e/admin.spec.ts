import { test, expect } from '@playwright/test'

/** Requires an isolated installed PHP+PostgreSQL+Redis instance and a dedicated admin test account. */
const email = process.env.E2E_ADMIN_EMAIL || ''
const password = process.env.E2E_ADMIN_PASSWORD || ''

test('unauthenticated browser is redirected from dashboard to login', async ({ page }) => {
  await page.goto('/')
  await expect(page).toHaveURL(/\/login(?:\?|$)/)
  await expect(page.getByRole('heading', { name: 'Вход в панель' })).toBeVisible()
})

test('end-to-end authentication, dashboard, audit and diagnostic job', async ({ page }) => {
  test.skip(!email || !password, 'Set E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD for an isolated installed instance.')
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Пароль').fill(password)
  if (process.env.E2E_ADMIN_MFA) await page.getByLabel(/Код TOTP/).fill(process.env.E2E_ADMIN_MFA)
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await expect(page).toHaveURL(/\/$/)
  await expect(page.getByRole('heading', { name: 'Обзор инфраструктурной платформы' })).toBeVisible()
  await page.getByRole('link', { name: 'Очередь и задачи' }).click()
  await expect(page.getByRole('heading', { name: 'Очередь и диагностические задачи' })).toBeVisible()
  page.once('dialog', dialog => dialog.accept())
  await page.getByRole('button', { name: 'Поставить в очередь' }).click()
  await expect(page.getByText(/Задача [a-f0-9-]+ добавлена/)).toBeVisible()
  await page.getByRole('button', { name: 'Проверить', exact: true }).click()
  await expect(page.getByText(/Попыток:/)).toBeVisible()
  if (process.env.E2E_EXPECT_WORKER === '1') {
    await expect.poll(async () => {
      await page.getByRole('button', { name: 'Проверить', exact: true }).click()
      return await page.locator('.panel .tag').allTextContents()
    }, { timeout: 30_000, intervals: [500, 1000, 2000] }).toContain('completed')
  }
  await page.getByRole('link', { name: 'Аудит' }).click()
  await expect(page.getByRole('heading', { name: 'Журнал аудита' })).toBeVisible()
  await expect(page.getByText('admin.job.requested').first()).toBeVisible()
  await page.getByRole('link', { name: 'Профиль и безопасность' }).click()
  await expect(page.getByRole('heading', { name: 'API-токены' })).toBeVisible()
  await page.getByRole('button', { name: 'Выйти' }).click()
  await expect(page).toHaveURL(/\/login(?:\?|$)/)
  await page.goto('/system')
  await expect(page).toHaveURL(/\/login(?:\?|$)/)
})


test('workspace administration: create organization and workspace via secured UI', async ({ page }) => {
  test.skip(!email || !password, 'Requires isolated installed 0.2.2 backend and admin test account.')
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Пароль').fill(password)
  if (process.env.E2E_ADMIN_MFA) await page.getByLabel(/Код TOTP/).fill(process.env.E2E_ADMIN_MFA)
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await page.getByRole('link', { name: 'Организации и пространства' }).click()
  await expect(page.getByRole('heading', { name: 'Организации и рабочие пространства' })).toBeVisible()
  const slug = `e2e-${Date.now()}`
  await page.getByLabel('Slug').first().fill(slug)
  await page.getByLabel('Название').first().fill('E2E Organization')
  await page.getByRole('button', { name: 'Создать организацию' }).click()
  await expect(page.getByText('Организация и её пространство созданы.')).toBeVisible()
  await expect(page.getByRole('option', { name: 'E2E Organization' })).toHaveCount(1)
  await page.getByLabel('Slug').last().fill('e2e-zone')
  await page.getByLabel('Название').last().fill('E2E workspace')
  await page.getByRole('button', { name: 'Создать', exact: true }).click()
  await expect(page.getByRole('button', { name: /E2E workspace \(owner\)/ })).toBeVisible()
})
