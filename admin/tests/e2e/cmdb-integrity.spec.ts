import { test, expect } from '@playwright/test'

/** Read-only end-to-end integrity probe against an installed, isolated staging deployment. */
test('CMDB integrity requires session and reports verified live PostgreSQL state', async ({ page }) => {
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD,
    'Requires installed staging and a dedicated admin account.')

  const anonymous = await page.request.get('/api/v1/admin/cmdb-integrity')
  expect(anonymous.status()).toBe(401)

  await page.goto('/login')
  await page.getByLabel('Email').fill(process.env.E2E_ADMIN_EMAIL!)
  await page.getByLabel('Пароль').fill(process.env.E2E_ADMIN_PASSWORD!)
  if (process.env.E2E_ADMIN_MFA) await page.getByLabel(/Код TOTP/).fill(process.env.E2E_ADMIN_MFA)
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await expect(page).toHaveURL(/\/$/)

  const response = await page.request.get('/api/v1/admin/cmdb-integrity')
  expect(response.status()).toBe(200)
  const report = await response.json() as {
    ok: boolean; checked_at: string; totals: Record<string, number>
    checks: Array<{ name: string; ok: boolean; count: number }>
  }
  expect(report.ok).toBe(true)
  expect(report.checks.length).toBeGreaterThanOrEqual(14)
  expect(report.checks.every(check => check.ok && check.count === 0)).toBe(true)
  expect(typeof report.totals.assets).toBe('number')
  expect(typeof report.totals.relationships).toBe('number')
  // The endpoint must never return raw host, IP, secret, or credential data.
  const body = JSON.stringify(report)
  expect(body).not.toMatch(/(?:encrypted_payload|api_token|password|ssh_key)/i)

  await expect(page.getByRole('heading', { name: 'Целостность Inventory / CMDB' })).toBeVisible()
  await expect(page.getByText('Согласована', { exact: true })).toBeVisible()
})
