import type { InfrastructureAsset, Driver, Target, TargetInput, ServiceGrant, Run, EnqueuedRun, Operation, PowerAction, Action, Problem } from './types.js'
export class ApiError extends Error {
  constructor(public readonly status: number, public readonly problem: Problem) { super(problem.title || `HTTP ${status}`); this.name = 'ApiError' }
}
/** Browser session API. No secret persistence, retries, or implicit power confirmations. */
export class CajeerServersClient {
  private readonly base: string
  private pending: Promise<unknown> = Promise.resolve()
  constructor(baseUrl = '/api/v1', private readonly token?: string, private readonly fetcher: typeof fetch = fetch) {
    this.base = baseUrl.replace(/\/+$/, '')
    if (typeof location !== 'undefined' && new URL(this.base, location.href).origin !== location.origin) throw new Error('SDK requires same-origin API')
    if (!this.base.startsWith('/') && !/^https:\/\//i.test(this.base)) throw new Error('HTTPS API URL required')
    if (this.base.startsWith('//')) throw new Error('Invalid API URL')
  }
  private async request<T>(method: string, path: string, body?: unknown, extra: Record<string,string> = {}): Promise<T> {
    const headers: Record<string,string> = { Accept: 'application/json', ...extra }
    if (this.token) headers.Authorization = `Bearer ${this.token}`
    if (body !== undefined) headers['Content-Type'] = 'application/json'
    const res = await this.fetcher(`${this.base}${path}`, { method, credentials: 'include', cache: 'no-store', redirect: 'error', headers, ...(body === undefined ? {} : { body: JSON.stringify(body) }) })
    if (res.status === 204) return undefined as T
    let json: unknown
    try { json = await res.json() } catch { throw new ApiError(res.status, { title: 'Invalid API response' }) }
    if (!res.ok) throw new ApiError(res.status, typeof json === 'object' && json !== null ? json as Problem : {})
    return json as T
  }
  private async data<T>(path: string): Promise<T> { return (await this.request<{data:T}>('GET',path)).data }
  private write<T>(method:string,path:string,body?:unknown,headers:Record<string,string>={}):Promise<T> {
    // CSRF rotates on issuance. Serialize writes within this client instance.
    const next = this.pending.then(async () => {
      if (this.token) throw new Error('Connector writes require a browser session, not a bearer token')
      const {csrf_token} = await this.request<{csrf_token:string}>('GET','/auth/csrf')
      if (!csrf_token) throw new Error('CSRF token missing')
      const result = await this.request<{data:T} | undefined>(method,path,body,{...headers,'X-CSRF-Token':csrf_token})
      return result?.data as T
    })
    this.pending = next.catch(() => undefined)
    return next
  }
  private target(id:string):string { if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id)) throw new Error('Invalid UUID'); return `/connectors/targets/${encodeURIComponent(id)}` }
  private id(id:string):string { if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id)) throw new Error('Invalid UUID'); return encodeURIComponent(id) }
  assets():Promise<InfrastructureAsset[]> { return this.data('/inventory/assets') }
  drivers():Promise<Driver[]> { return this.data('/connectors/drivers') }
  targets(workspace:string,limit=50,offset=0):Promise<Target[]> { return this.data(`/connectors/targets?workspace_id=${this.id(workspace)}&limit=${limit}&offset=${offset}`) }
  getTarget(id:string):Promise<Target> { return this.data(this.target(id)) }
  createTarget(input:TargetInput):Promise<Target> { return this.write('POST','/connectors/targets',input) }
  updateTarget(id:string,etag:string,patch:Partial<Pick<Target,'name'|'endpoint_host'|'endpoint_port'|'asset_id'|'state'>>):Promise<Target> { return this.write('PATCH',this.target(id),patch,{'If-Match':etag}) }
  deleteTarget(id:string,etag:string):Promise<void> { return this.write('DELETE',this.target(id),undefined,{'If-Match':etag}) }
  bindings(id:string):Promise<unknown> { return this.data(`${this.target(id)}/credential-bindings`) }
  unbind(id:string,etag:string,bindingId:string):Promise<void> { return this.write('DELETE',`${this.target(id)}/credential-bindings/${this.id(bindingId)}`,undefined,{'If-Match':etag}) }
  validateRotation(id:string,etag:string,revision:number):Promise<unknown> { return this.write('POST',`${this.target(id)}/validate-rotation`,{revision},{'If-Match':etag}) }
  bind(id:string,etag:string,credentialId:string,purpose:string,attachToAsset=true):Promise<unknown> { return this.write('POST',`${this.target(id)}/credential-bindings`,{credential_id:credentialId,purpose,attach_to_asset:attachToAsset},{'If-Match':etag}) }
  authorizeUse(credentialId:string,assetId:string,purpose:string,password:string,totp:string):Promise<unknown> { return this.write('POST',`/vault/credentials/${this.id(credentialId)}/authorize-use`,{asset_id:assetId,purpose,password,totp}) }
  operate(id:string,etag:string,operation:Operation):Promise<unknown> { return this.write('POST',`${this.target(id)}/${operation}`,undefined,{'If-Match':etag}) }
  grant(id:string,etag:string,operation:Operation,ttlSeconds:number,intervalSeconds?:number):Promise<ServiceGrant> { return this.write('POST',`${this.target(id)}/service-grants`,{operation,ttl_seconds:ttlSeconds,...(intervalSeconds===undefined?{}:{interval_seconds:intervalSeconds})},{'If-Match':etag}) }
  grants(id:string):Promise<ServiceGrant[]> { return this.data(`${this.target(id)}/service-grants`) }
  revokeGrant(id:string,grantId:string):Promise<void> { return this.write('DELETE',`${this.target(id)}/service-grants/${this.id(grantId)}`) }
  enqueue(id:string,grantId:string,key:string,collector?:string):Promise<EnqueuedRun> { return this.write('POST',`${this.target(id)}/runs`,{grant_id:grantId,...(collector?{collector}:{})},{'Idempotency-Key':key}) }
  runs(id:string,limit=50,offset=0):Promise<Run[]> { return this.data(`${this.target(id)}/runs?limit=${limit}&offset=${offset}`) }
  run(id:string,runId:string):Promise<Run> { return this.data(`${this.target(id)}/runs/${this.id(runId)}`) }
  cancel(id:string,runId:string):Promise<Run> { return this.write('POST',`${this.target(id)}/runs/${this.id(runId)}/cancel`) }
  resources(id:string,limit=50,offset=0):Promise<unknown> { return this.data(`${this.target(id)}/resources?limit=${limit}&offset=${offset}`) }
  actions(id:string,offset=0):Promise<Action[]> { return this.data(`${this.target(id)}/actions?offset=${offset}`) }
  action(id:string,actionId:string):Promise<Action> { return this.data(`${this.target(id)}/actions/${this.id(actionId)}`) }
  power(id:string,etag:string,action:PowerAction,externalId:string,confirmation:string,key:string):Promise<Action> {
    if (confirmation!==`${action} ${externalId}`) throw new Error('Explicit resource confirmation required')
    return this.write('POST',`${this.target(id)}/actions`,{action,external_id:externalId,confirm:confirmation},{'If-Match':etag,'Idempotency-Key':key})
  }
  refreshAction(id:string,etag:string,actionId:string):Promise<Action> { return this.write('POST',`${this.target(id)}/actions/${this.id(actionId)}/refresh`,undefined,{'If-Match':etag}) }
  acknowledgeAction(id:string,etag:string,actionId:string,confirmation:string):Promise<Action> {
    if (confirmation!=='I verified the remote result') throw new Error('Explicit external verification required')
    return this.write('POST',`${this.target(id)}/actions/${this.id(actionId)}/acknowledge`,{confirm:confirmation},{'If-Match':etag})
  }
}
