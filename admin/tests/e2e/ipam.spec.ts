import { test, expect } from '@playwright/test'

/** Requires installed isolated PHP+Nuxt+PostgreSQL staging. No device configuration is changed. */
const email = process.env.E2E_ADMIN_EMAIL || ''
const password = process.env.E2E_ADMIN_PASSWORD || ''

test('IPAM end-to-end: subnet, interface, reservation, assignment and release', async ({ page }) => {
  test.skip(!email || !password, 'Provide dedicated staging E2E admin credentials.')
  const suffix = Date.now().toString(36)
  const segment = 1 + Math.floor(Math.random() * 200)
  const cidr = `198.19.${segment}.0/24`
  const ipAddress = `198.19.${segment}.73`
  const name = `E2E-Network-${suffix}`
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Пароль').fill(password)
  if (process.env.E2E_ADMIN_MFA) await page.getByLabel(/Код TOTP/).fill(process.env.E2E_ADMIN_MFA)
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await expect(page).toHaveURL(/\/$/)

  // Create a temporary asset via the authenticated same-origin API. The IPAM controls
  // below are exercised through the browser. All temporary records are released.
  const workspacesResponse = await page.request.get('/api/v1/workspaces')
  expect(workspacesResponse.ok()).toBeTruthy()
  const workspaces = (await workspacesResponse.json()).data as {id:string;role:string}[]
  const selected = workspaces.find(w=>w.role==='owner' || w.role==='manager')
  expect(selected).toBeTruthy()
  const ws = selected!.id
  const token = async () => {
    const result = await page.request.get('/api/v1/auth/csrf')
    expect(result.ok()).toBeTruthy()
    return (await result.json()).csrf_token as string
  }
  const assetResponse = await page.request.post('/api/v1/inventory/assets', {
    data: { workspace_id:ws, type:'server', name:`E2E-IPAM-node-${suffix}` },
    headers: { 'X-CSRF-Token':await token() },
  })
  expect(assetResponse.status()).toBe(201)
  const asset = (await assetResponse.json()).data as {id:string;etag:string}
  try {
    await page.getByRole('link', { name:'Сети и IPAM' }).click()
    await expect(page.getByRole('heading', { name:'Сети и IPAM' })).toBeVisible()
    await page.locator('#ipam-workspace').selectOption(ws)
    const networks = page.locator('section.panel').filter({ has:page.getByRole('heading',{name:/Подсети/}) })
    await networks.locator('input').nth(0).fill(name)
    await networks.locator('input').nth(1).fill(cidr)
    await networks.getByRole('button',{name:'Добавить подсеть'}).click()
    await expect(networks.getByRole('cell',{name:cidr,exact:true})).toBeVisible()
    const interfaces = page.locator('section.panel').filter({ has:page.getByRole('heading',{name:/Сетевые интерфейсы/}) })
    await interfaces.locator('select').first().selectOption(asset.id)
    await interfaces.locator('input').first().fill('e2e0')
    await interfaces.getByRole('button',{name:'Добавить интерфейс'}).click()
    await expect(interfaces.getByRole('cell',{name:'e2e0'})).toBeVisible()
    const ips = page.locator('section.panel').filter({ has:page.getByRole('heading',{name:/IP-адреса/}) })
    const netOption=ips.locator('select').first().locator('option').filter({hasText:name})
    await ips.locator('select').first().selectOption((await netOption.getAttribute('value'))!)
    await ips.locator('input').first().fill(ipAddress)
    await ips.getByRole('button',{name:'Зарезервировать адрес'}).click()
    const row = ips.locator('tr').filter({hasText:ipAddress})
    await expect(row.getByText('reserved')).toBeVisible()
    const choices = row.locator('select')
    const ifaceOption=choices.locator('option').filter({hasText:'e2e0'})
    await choices.selectOption((await ifaceOption.getAttribute('value'))!)
    await row.getByRole('button',{name:'Назначить'}).click()
    await expect(ips.locator('tr').filter({hasText:ipAddress}).getByText('assigned')).toBeVisible()
    await ips.locator('tr').filter({hasText:ipAddress}).getByRole('button',{name:'Отвязать'}).click()
    await expect(ips.locator('tr').filter({hasText:ipAddress}).getByText('reserved')).toBeVisible()
    page.once('dialog',d=>d.accept())
    await ips.locator('tr').filter({hasText:ipAddress}).getByRole('button',{name:'Удалить'}).click()
    await expect(ips.locator('tr').filter({hasText:ipAddress})).toHaveCount(0)
    page.once('dialog',d=>d.accept())
    await interfaces.locator('tr').filter({hasText:'e2e0'}).getByRole('button',{name:'Удалить'}).click()
    await expect(interfaces.locator('tr').filter({hasText:'e2e0'})).toHaveCount(0)
    page.once('dialog',d=>d.accept())
    await networks.locator('tr').filter({hasText:name}).getByRole('button',{name:'Удалить'}).click()
    await expect(networks.locator('tr').filter({hasText:name})).toHaveCount(0)
  } finally {
    // Best-effort cleanup even when a browser assertion fails halfway through.
    // Always unassign before releasing the address, then delete the interface and subnet.
    const query = `?workspace_id=${encodeURIComponent(ws)}`
    try {
      const list = async (kind:string) => {
        const r = await page.request.get(`/api/v1/ipam/${kind}${query}&limit=100`)
        if (!r.ok()) return [] as any[]
        return (await r.json()).data as any[]
      }
      const addresses = await list('addresses')
      const ip = addresses.find(a=>a.address===ipAddress)
      if (ip) {
        if (ip.interface_id) {
          const un = await page.request.post(`/api/v1/ipam/addresses/${ip.id}/unassign${query}`,{
            headers:{'X-CSRF-Token':await token(),'If-Match':ip.etag},
          })
          if (un.ok()) ip.etag = (await un.json()).data.etag
        }
        await page.request.delete(`/api/v1/ipam/addresses/${ip.id}${query}`,{
          headers:{'X-CSRF-Token':await token(),'If-Match':ip.etag},
        })
      }
      const interfaces = await list('interfaces')
      const port = interfaces.find(i=>i.asset_id===asset.id && i.name==='e2e0')
      if (port) await page.request.delete(`/api/v1/ipam/interfaces/${port.id}${query}`,{
        headers:{'X-CSRF-Token':await token(),'If-Match':port.etag},
      })
      const networks = await list('networks')
      const net = networks.find(n=>n.name===name && n.cidr===cidr)
      if (net) await page.request.delete(`/api/v1/ipam/networks/${net.id}${query}`,{
        headers:{'X-CSRF-Token':await token(),'If-Match':net.etag},
      })
    } finally {
      await page.request.delete(`/api/v1/inventory/assets/${asset.id}`, {
        headers: { 'X-CSRF-Token':await token(),'If-Match':asset.etag },
      })
    }
  }
})
