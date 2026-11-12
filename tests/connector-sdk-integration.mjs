import assert from 'node:assert/strict'
import {createServer} from 'node:https'
import {readFileSync} from 'node:fs'
import {CajeerServersClient,ApiError} from './index.js'
const dir=process.argv[2], id='11111111-1111-4111-8111-111111111111', etag=`"${id}:2"`
let csrf=0, posts=0
const server=createServer({key:readFileSync(`${dir}/key.pem`),cert:readFileSync(`${dir}/cert.pem`)},async(req,res)=>{
  const chunks=[];for await(const chunk of req)chunks.push(chunk);const body=chunks.length?JSON.parse(Buffer.concat(chunks)):null
  const reply=(status,data)=>{res.writeHead(status,{'Content-Type':'application/json'});res.end(JSON.stringify(data))}
  if(req.url==='/api/v1/auth/csrf')return reply(200,{csrf_token:`csrf-${++csrf}`})
  if(req.url===`/api/v1/connectors/targets/${id}`&&req.method==='GET')return reply(200,{data:{id,etag}})
  if(req.url===`/api/v1/connectors/targets/${id}/actions`&&req.method==='POST'){
    assert.equal(req.headers['x-csrf-token'],`csrf-${csrf}`);assert.equal(req.headers['if-match'],etag);assert.equal(req.headers['idempotency-key'],'test-idempotency-0001');assert.deepEqual(body,{action:'vm.start',external_id:'qemu:101',confirm:'vm.start qemu:101'});posts++;return reply(202,{data:{id,status:'submitted'}})
  }
  if(req.url===`/api/v1/connectors/targets/${id}/runs`&&req.method==='POST'){
    assert.equal(req.headers['x-csrf-token'],`csrf-${csrf}`);assert.equal(req.headers['idempotency-key'],'collect-01');assert.deepEqual(body,{grant_id:id,collector:'proxmox.inventory'});return reply(202,{data:{id,deduplicated:false}})
  }
  if(req.url===`/api/v1/connectors/targets/${id}/collection-plans`&&req.method==='POST'){
    assert.equal(req.headers['x-csrf-token'],`csrf-${csrf}`);assert.equal(req.headers['if-match'],etag);assert.deepEqual(body,{name:'Inventory',collector:'proxmox.inventory',operation:'sync',ttl_seconds:3600,interval_seconds:300,jitter_seconds:5});return reply(201,{data:{id,enabled:true}})
  }
  if(req.url===`/api/v1/connectors/targets/${id}/collection-plans?limit=50&offset=0`&&req.method==='GET')return reply(200,{data:[{id,enabled:true}]})
  if(req.url===`/api/v1/connectors/targets/${id}/collection-plans/${id}/state`&&req.method==='POST'){
    assert.equal(req.headers['x-csrf-token'],`csrf-${csrf}`);assert.deepEqual(body,{enabled:false});return reply(200,{data:{id,enabled:false}})
  }
  if(req.url===`/api/v1/connectors/targets/${id}/collection-plans/${id}/runs`&&req.method==='POST'){
    assert.equal(req.headers['x-csrf-token'],`csrf-${csrf}`);assert.equal(req.headers['idempotency-key'],'plan-redrive');assert.deepEqual(body,{redrive_of:id});return reply(202,{data:{id,deduplicated:false}})
  }
  if(req.url===`/api/v1/connectors/targets/${id}/runs?limit=50&offset=0&plan_id=${id}`&&req.method==='GET')return reply(200,{data:[{id,collection_plan_id:id}]})
  if(req.url.startsWith(`/api/v1/connectors/targets/${id}/runs?`)&&req.method==='GET')return reply(403,{title:'Insufficient permission',code:'permission_denied'})
  reply(404,{title:'Not found'})
})
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve))
try {
  const c=new CajeerServersClient(`https://127.0.0.1:${server.address().port}/api/v1`)
  assert.equal((await c.getTarget(id)).etag,etag)
  assert.throws(()=>c.power(id,etag,'vm.start','qemu:101','yes','test-idempotency-0001'))
  const r=await c.power(id,etag,'vm.start','qemu:101','vm.start qemu:101','test-idempotency-0001');assert.equal(r.status,'submitted');assert.equal(posts,1)
  assert.equal((await c.enqueue(id,id,'collect-01','proxmox.inventory')).id,id)
  assert.equal((await c.createCollectionPlan(id,etag,{name:'Inventory',collector:'proxmox.inventory',operation:'sync',ttl_seconds:3600,interval_seconds:300,jitter_seconds:5})).id,id)
  assert.equal((await c.collectionPlans(id))[0].id,id)
  assert.equal((await c.setCollectionPlanEnabled(id,id,false)).enabled,false)
  assert.equal((await c.runCollectionPlan(id,id,'plan-redrive',id)).id,id)
  assert.equal((await c.runs(id,50,0,id))[0].collection_plan_id,id)
  await assert.rejects(c.runs(id),e=>e instanceof ApiError&&e.status===403&&e.problem.code==='permission_denied')
  console.log('TypeScript SDK compile, real TLS fetch, CSRF, ETag, confirmation, collection plans, typed errors PASS')
}finally{await new Promise(resolve=>server.close(resolve))}
