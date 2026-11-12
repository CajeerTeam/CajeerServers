import {freshAdminTotp} from './admin-totp'
import { test, expect } from '@playwright/test'

/** Runs against an isolated installed staging instance with PostgreSQL, PHP and Nuxt. */
const email=process.env.E2E_ADMIN_EMAIL||''
const password=process.env.E2E_ADMIN_PASSWORD||''

test('CMDB: persisted topology, typed links, version conflict and browser visualization',async ({page})=>{
  test.skip(!email||!password,'Requires staging administrator credentials')
  const suffix=Date.now().toString(36)
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Пароль').fill(password)
  {const code=await freshAdminTotp();if(code)await page.getByLabel(/Код TOTP/).fill(code)}
  await page.getByRole('button',{name:'Войти',exact:true}).click()
  await expect(page).toHaveURL(/\/$/)
  const spaces=await page.request.get('/api/v1/workspaces')
  expect(spaces.status()).toBe(200)
  const ws=((await spaces.json()).data as {id:string;role:string}[]).find(w=>['owner','manager'].includes(w.role))?.id
  expect(ws).toBeTruthy()
  const csrf=async()=>{
    const r=await page.request.get('/api/v1/auth/csrf')
    expect(r.status()).toBe(200)
    return (await r.json()).csrf_token as string
  }
  const created:{id:string;etag:string}[]=[]
  let edge:{id:string;etag:string}|null=null
  try{
    for(const [type,name] of [['cluster','cluster'],['hypervisor_node','node']] as const){
      const result=await page.request.post('/api/v1/inventory/assets',{
        data:{workspace_id:ws,type,name:`e2e-topology-${name}-${suffix}`},headers:{'X-CSRF-Token':await csrf()},
      })
      expect(result.status()).toBe(201)
      created.push((await result.json()).data)
    }
    const result=await page.request.post('/api/v1/inventory/relationships',{
      data:{workspace_id:ws,source_asset_id:created[0]!.id,target_asset_id:created[1]!.id,kind:'cluster_contains'},
      headers:{'X-CSRF-Token':await csrf()},
    })
    expect(result.status()).toBe(201)
    edge=(await result.json()).data
    const duplicate=await page.request.post('/api/v1/inventory/relationships',{
      data:{workspace_id:ws,source_asset_id:created[0]!.id,target_asset_id:created[1]!.id,kind:'cluster_contains'},
      headers:{'X-CSRF-Token':await csrf()},
    })
    expect(duplicate.status()).toBe(409)
    const topology=await page.request.get(`/api/v1/inventory/topology?workspace_id=${ws}`)
    expect(topology.status()).toBe(200)
    const graph=(await topology.json()).data as {nodes:{id:string}[];edges:{id:string}[]}
    expect(graph.nodes.some(n=>n.id===`asset:${created[0]!.id}`)).toBeTruthy()
    expect(graph.edges.some(e=>e.id===edge!.id)).toBeTruthy()
    await page.getByRole('link',{name:'Связи и топология'}).click()
    await expect(page.getByRole('heading',{name:'Связи и топология инфраструктуры'})).toBeVisible()
    await page.locator('#topology-workspace').selectOption(ws!)
    await expect(page.getByText(`e2e-topology-cluster-${suffix}`).first()).toBeVisible()
    await expect(page.getByText(`e2e-topology-node-${suffix}`).first()).toBeVisible()
    const stale=await page.request.patch(`/api/v1/inventory/relationships/${edge!.id}?workspace_id=${ws}`,{
      data:{kind:'depends_on'},
      headers:{'X-CSRF-Token':await csrf(),'If-Match':`"${edge!.id}:999"`},
    })
    expect(stale.status()).toBe(412)
  }finally{
    if(edge){
      try{
        const latest=await page.request.get(`/api/v1/inventory/relationships/${edge.id}?workspace_id=${ws}`)
        if(latest.ok()){
          const etag=(await latest.json()).data.etag as string
          await page.request.delete(`/api/v1/inventory/relationships/${edge.id}?workspace_id=${ws}`,{
            headers:{'If-Match':etag,'X-CSRF-Token':await csrf()},
          })
        }
      }catch{/* cleanup best effort */}
    }
    for(const asset of created.reverse()){
      try{
        const latest=await page.request.get(`/api/v1/inventory/assets/${asset.id}`)
        if(!latest.ok())continue
        const etag=(await latest.json()).data.etag as string
        await page.request.delete(`/api/v1/inventory/assets/${asset.id}`,{
          headers:{'If-Match':etag,'X-CSRF-Token':await csrf()},
        })
      }catch{/* cleanup best effort */}
    }
  }
})
