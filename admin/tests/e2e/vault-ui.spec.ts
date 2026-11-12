import {freshAdminTotp} from './admin-totp'
import { test, expect, type Page } from '@playwright/test'

/**
 * True browser -> Nuxt -> PHP API -> PostgreSQL/Vault scenario. NO route mocks.
 * Never run against production. Only synthetic throwaway secrets are used.
 */
const enabled = process.env.E2E_ALLOW_WRITES === '1'
  && process.env.E2E_ISOLATED_STAGING === '1' && process.env.E2E_VAULT_UI === '1'
const email = process.env.E2E_ADMIN_EMAIL || ''
const password = process.env.E2E_ADMIN_PASSWORD || ''

// Secret form values can be captured by Playwright traces/screenshots on failure.
// Explicitly disable both for this test; do not log request bodies.
test.use({ trace: 'off', screenshot: 'off', video: 'off' })

type VaultCredential = { id: string; name: string; version: number; secret_version: number; etag: string; state: string }
const api = '/api/v1'

async function signIn(page: Page, username: string, pass: string, mfa?: string) {
  await page.goto('/login')
  await page.getByLabel('Email').fill(username)
  await page.getByLabel('Пароль').fill(pass)
  if (mfa) await page.getByLabel(/Код TOTP/).fill(mfa)
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await expect(page).toHaveURL(/\/$/)
}

async function csrf(page: Page): Promise<string> {
  const response = await page.request.get(`${api}/auth/csrf`)
  expect(response.ok()).toBe(true)
  const body = await response.json()
  return body.csrf_token as string
}

async function getCredential(page: Page, id: string): Promise<VaultCredential> {
  const response = await page.request.get(`${api}/vault/credentials/${id}`)
  expect(response.ok()).toBe(true)
  return (await response.json()).data as VaultCredential
}

async function neverLeaks(page: Page, id: string, secrets: string[]) {
  for (const path of [`/vault/credentials/${id}`, `/vault/credentials/${id}/versions`]) {
    const response = await page.request.get(`${api}${path}`)
    expect(response.ok()).toBe(true)
    const value = await response.text()
    for (const secret of secrets) expect(value.includes(secret)).toBe(false)
    expect(value.includes('encrypted_payload')).toBe(false)
  }
  const body = await page.locator('body').innerText()
  for (const secret of secrets) expect(body.includes(secret)).toBe(false)
}

test('Vault UI: create, edit/ETag, disable, two-stage rotation, replace, archive', async ({ page }) => {
  test.skip(!enabled || !email || !password, 'Vault E2E requires explicitly authorized disposable staging with initialized Vault')
  await signIn(page, email, password, await freshAdminTotp())
  const workspaces = await page.request.get(`${api}/workspaces`)
  expect(workspaces.ok()).toBe(true)
  const spaces = (await workspaces.json()).data as { id: string; role: string }[]
  const workspace = spaces.find(s => ['owner', 'manager'].includes(s.role))?.id
  expect(workspace).toBeTruthy()
  const unique = `vault-ui-e2e-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
  const csrfDenied = await page.request.post(`${api}/vault/credentials`, {
    data: { workspace_id: workspace, name: `csrf-denied-${unique}`, type: 'api_token', secret: 'no-csrf-test-secret' },
  })
  expect(csrfDenied.status()).toBe(403)
  const tokens = [`synthetic-initial-${unique}`, `synthetic-pending-${unique}`,
    `synthetic-next-${unique}`, `synthetic-replacement-${unique}`]
  let id = ''
  try {
    await page.goto(`/vault?workspace_id=${workspace}`)
    await expect(page.getByRole('heading', { name: 'Credentials Vault' })).toBeVisible()
    await page.locator('#vault-create-name').fill(unique)
    await page.locator('#vault-create-type').selectOption('api_token')
    await page.locator('#vault-create-description').fill('Disposable Vault UI staging record')
    await page.locator('#vault-create-secret').fill(tokens[0])
    await page.getByRole('button', { name: 'Зашифровать и создать' }).click()
    await expect(page.getByRole('alert')).toContainText('Credential создан')
    await expect(page.getByTestId('vault-details')).toBeVisible()
    id = (await page.getByTestId('vault-details').locator('.mono').first().innerText()).trim()
    expect(id).toMatch(/^[0-9a-f-]{36}$/)
    expect(await page.locator('#vault-create-secret').inputValue()).toBe('')
    await neverLeaks(page, id, tokens)
    const noPrecondition = await page.request.patch(`${api}/vault/credentials/${id}`, {
      headers: { 'X-CSRF-Token': await csrf(page) },
      data: { name: 'should-not-write-without-etag' },
    })
    expect(noPrecondition.status()).toBe(428)

    // Multi-tenant scoping: this new credential cannot show in another workspace's list.
    const other = spaces.find(s => s.id !== workspace)
    if (other) {
      const listing = await page.request.get(`${api}/vault/credentials?workspace_id=${other.id}`)
      expect(listing.ok()).toBe(true)
      const data = (await listing.json()).data as { id: string }[]
      expect(data.some(row => row.id === id)).toBe(false)
      await page.locator('#vault-workspace').selectOption(other.id)
      await expect(page.getByTestId('vault-details')).toHaveCount(0)
      await expect(page.getByTestId('vault-list').getByText(unique)).toHaveCount(0)
      await page.locator('#vault-workspace').selectOption(workspace!)
      await expect(page.getByTestId('vault-list').getByText(unique)).toBeVisible()
      await page.getByTestId('vault-list').locator('tr').filter({ hasText: unique })
        .getByRole('button', { name: 'Управлять' }).click()
      await expect(page.getByTestId('vault-details')).toBeVisible()
    }

    // Enable/disable via real UI (backend enforces permission + If-Match).
    await page.locator('#vault-edit-state').selectOption('disabled')
    await page.getByRole('button', { name: 'Сохранить метаданные' }).click()
    await expect(page.getByRole('alert')).toContainText('Метаданные')
    expect((await getCredential(page, id)).state).toBe('disabled')
    await expect(page.getByRole('heading', { name: 'Обновление секрета' })).toHaveCount(0)
    await page.locator('#vault-edit-state').selectOption('active')
    await page.getByRole('button', { name: 'Сохранить метаданные' }).click()
    expect((await getCredential(page, id)).state).toBe('active')

    // External concurrent update invalidates the rendered form's ETag. UI must reload,
    // show conflict and never auto-retry a potentially destructive write.
    const latest = await getCredential(page, id)
    const external = await page.request.patch(`${api}/vault/credentials/${id}`, {
      headers: { 'If-Match': latest.etag, 'X-CSRF-Token': await csrf(page) },
      data: { description: 'Updated by a second client' },
    })
    expect(external.status()).toBe(200)
    await page.locator('#vault-edit-description').fill('stale change must not persist')
    await page.getByRole('button', { name: 'Сохранить метаданные' }).click()
    await expect(page.getByRole('alert')).toContainText('Запись уже изменена')
    expect((await getCredential(page, id)).version).toBe(latest.version + 1)
    await expect(page.locator('#vault-edit-description')).toHaveValue('Updated by a second client')

    // Stage then cancel. The active version is unchanged; only metadata version moves.
    await page.locator('#vault-secret-mode').selectOption('stage')
    await page.locator('#vault-operation-secret').fill(tokens[1])
    await page.getByRole('button', { name: 'Подготовить новую версию' }).click()
    await expect(page.getByTestId('vault-pending')).toBeVisible()
    const afterStage = await getCredential(page, id)
    expect(afterStage.secret_version).toBe(1)
    await neverLeaks(page, id, tokens)
    page.once('dialog', dialog => dialog.accept())
    await page.getByRole('button', { name: 'Отменить ротацию' }).click()
    await expect(page.getByTestId('vault-pending')).toHaveCount(0)
    expect((await getCredential(page, id)).secret_version).toBe(1)

    // Stage and explicitly activate a different version after external validation.
    await page.locator('#vault-operation-secret').fill(tokens[2])
    await page.getByRole('button', { name: 'Подготовить новую версию' }).click()
    await expect(page.getByRole('button', { name: 'Активировать версию' })).toBeDisabled()
    await page.getByLabel(/Я проверил новую версию/).check()
    await page.getByRole('button', { name: 'Активировать версию' }).click()
    await expect(page.getByTestId('vault-pending')).toHaveCount(0)
    expect((await getCredential(page, id)).secret_version).toBeGreaterThan(1)
    await neverLeaks(page, id, tokens)

    // Direct replacement uses a different endpoint, also write-only.
    await page.locator('#vault-secret-mode').selectOption('replace')
    await page.locator('#vault-operation-secret').fill(tokens[3])
    const versionBefore = (await getCredential(page, id)).version
    page.once('dialog', dialog => dialog.accept())
    await page.getByRole('button', { name: 'Заменить секрет' }).click()
    await expect(page.getByRole('alert')).toContainText('Секрет заменён')
    expect((await getCredential(page, id)).version).toBe(versionBefore + 1)
    await neverLeaks(page, id, tokens)

    // Archive via UI and verify the credential becomes invisible to REST list/show.
    page.once('dialog', dialog => dialog.accept())
    await page.getByRole('button', { name: 'Архивировать credential' }).click()
    await expect(page.getByRole('alert')).toContainText('архивирован')
    expect((await page.request.get(`${api}/vault/credentials/${id}`)).status()).toBe(404)
    id = ''
  } finally {
    if (id) {
      const current = await page.request.get(`${api}/vault/credentials/${id}`)
      if (current.ok()) {
        const row = (await current.json()).data as VaultCredential
        const deletion = await page.request.delete(`${api}/vault/credentials/${id}`, {
          headers: { 'If-Match': row.etag, 'X-CSRF-Token': await csrf(page) },
        })
        expect(deletion.ok()).toBe(true)
      }
    }
  }
})

test('Vault UI: typed secret profile is sent encrypted, never reflected in metadata', async ({ page }) => {
  test.skip(!enabled || !email || !password, 'Requires writable isolated Vault staging')
  await signIn(page, email, password, await freshAdminTotp())
  const spaces = (await (await page.request.get(`${api}/workspaces`)).json()).data as { id: string; role: string }[]
  const ws = spaces.find(s => ['owner', 'manager'].includes(s.role))?.id
  expect(ws).toBeTruthy()
  const label = `vault-typed-e2e-${Date.now()}`
  const token = `synthetic-secret-${label}`
  let id = ''
  try {
    await page.goto(`/vault?workspace_id=${ws}`)
    await page.locator('#vault-create-type').selectOption('proxmox_api')
    await page.locator('#vault-create-name').fill(label)
    await page.locator('#vault-create-token_id').fill('root@pam!e2e')
    await page.locator('#vault-create-token_secret').fill(token)
    const invalid = await page.request.post(`${api}/vault/credentials`, {
      headers: { 'X-CSRF-Token': await csrf(page) },
      data: { workspace_id: ws, name: `invalid-${label}`, type: 'proxmox_api',
        secret: { token_id: 'invalid', token_secret: token } },
    })
    expect(invalid.status()).toBe(422)
    await page.getByRole('button', { name: 'Зашифровать и создать' }).click()
    await expect(page.getByRole('alert')).toContainText('Credential создан')
    id = (await page.getByTestId('vault-details').locator('.mono').first().innerText()).trim()
    await expect(page.locator('#vault-create-token_secret')).toHaveValue('')
    await neverLeaks(page, id, [token])
  } finally {
    if (id) {
      const row = await getCredential(page, id)
      const response = await page.request.delete(`${api}/vault/credentials/${id}`, {
        headers: { 'If-Match': row.etag, 'X-CSRF-Token': await csrf(page) },
      })
      expect(response.ok()).toBe(true)
    }
  }
})

// Optional separate viewer identity for negative RBAC/CSRF checks on staging.
test('Vault UI: read-only identity cannot edit or post secret payloads', async ({ page }) => {
  const viewerEmail = process.env.E2E_VAULT_VIEWER_EMAIL || ''
  const viewerPass = process.env.E2E_VAULT_VIEWER_PASSWORD || ''
  test.skip(!enabled || !viewerEmail || !viewerPass, 'Requires dedicated staging viewer identity')
  await signIn(page, viewerEmail, viewerPass, process.env.E2E_VAULT_VIEWER_MFA)
  const spaces = await page.request.get(`${api}/workspaces`)
  expect(spaces.ok()).toBe(true)
  const ws = ((await spaces.json()).data as { id: string; role: string }[]).find(s => s.role === 'viewer')?.id
  test.skip(!ws, 'Viewer must be attached to a workspace')
  await page.goto(`/vault?workspace_id=${ws}`)
  await expect(page.getByRole('heading', { name: 'Credentials Vault' })).toBeVisible()
  await expect(page.getByTestId('vault-create')).toHaveCount(0)
  const unauthorized = await page.request.post(`${api}/vault/credentials`, {
    headers: { 'X-CSRF-Token': await csrf(page) },
    data: { workspace_id: ws, name: 'should-not-exist', type: 'api_token', secret: 'synthetic-denied' },
  })
  expect(unauthorized.status()).toBe(403)
  const listing = await page.request.get(`${api}/vault/credentials?workspace_id=${ws}`)
  expect(listing.ok()).toBe(true)
  const visible = (await listing.json()).data as VaultCredential[]
  if (visible.length) {
    const record = visible[0]
    const forbiddenPatch = await page.request.patch(`${api}/vault/credentials/${record.id}`, {
      headers: { 'X-CSRF-Token': await csrf(page), 'If-Match': record.etag },
      data: { description: 'viewer-must-not-update' },
    })
    expect(forbiddenPatch.status()).toBe(403)
    const forbiddenDelete = await page.request.delete(`${api}/vault/credentials/${record.id}`, {
      headers: { 'X-CSRF-Token': await csrf(page), 'If-Match': record.etag },
    })
    expect(forbiddenDelete.status()).toBe(403)
  }
})
