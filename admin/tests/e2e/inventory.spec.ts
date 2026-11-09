import { test, expect } from '@playwright/test'
/** Isolated installed PostgreSQL/Nuxt backend only. Creates its own asset; leaves no infra changes. */
const email = process.env.E2E_ADMIN_EMAIL || ''
const password = process.env.E2E_ADMIN_PASSWORD || ''

test('inventory CRUD, versioned edits, soft-delete and restore in real Admin UI', async ({ page }) => {
  test.skip(!email || !password, 'Provide staging E2E admin credentials for a non-production installed instance.')
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Пароль').fill(password)
  if (process.env.E2E_ADMIN_MFA) await page.getByLabel(/Код TOTP/).fill(process.env.E2E_ADMIN_MFA)
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await expect(page).toHaveURL(/\/$/)
  await page.getByRole('link', { name: 'Инвентаризация' }).click()
  await expect(page.getByRole('heading', { name: 'Inventory / CMDB' })).toBeVisible()
  const unique = `E2E-node-${Date.now()}`
  await page.getByLabel('Имя', { exact: true }).fill(unique)
  await page.getByLabel('IP-адреса (через запятую)').fill('192.0.2.42')
  await page.getByLabel('Теги (через запятую)').fill('e2e,staging')
  await page.getByRole('button', { name: 'Сохранить' }).click()
  await expect(page.getByRole('status').getByText('Объект создан.')).toBeVisible()
  const assetRow = page.locator('tr').filter({ hasText: unique })
  await expect(assetRow).toBeVisible()
  await assetRow.getByRole('button', { name: 'Изменить' }).click()
  await page.getByLabel('Имя', { exact: true }).fill(unique + '-updated')
  await page.getByRole('button', { name: 'Сохранить' }).click()
  await expect(page.getByRole('status').getByText('Изменения сохранены.')).toBeVisible()
  page.once('dialog', dialog => dialog.accept())
  await page.locator('tr').filter({ hasText: unique + '-updated' }).getByRole('button', { name: 'Удалить' }).click()
  await expect(page.getByRole('status').getByText('Объект удалён.')).toBeVisible()
  await page.getByLabel('Показывать удалённые').check()
  await page.locator('tr').filter({ hasText: unique + '-updated' }).getByRole('button', { name: 'Восстановить' }).click()
  await expect(page.getByRole('status').getByText('Объект восстановлен.')).toBeVisible()
  // Clean up the E2E asset after verifying restore.
  page.once('dialog', dialog => dialog.accept())
  await page.locator('tr').filter({ hasText: unique + '-updated' }).getByRole('button', { name: 'Удалить' }).click()
  await expect(page.getByRole('status').getByText('Объект удалён.')).toBeVisible()
})
