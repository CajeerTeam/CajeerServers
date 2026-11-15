import process from 'node:process'
import {readFileSync} from 'node:fs'

/** Fail closed before browser tests can modify an infrastructure inventory. */
const required=['E2E_BASE_URL','E2E_ADMIN_EMAIL','E2E_ADMIN_PASSWORD','E2E_ALLOW_WRITES','E2E_ISOLATED_STAGING','E2E_ADMIN_TOTP_SECRET','E2E_CONNECTOR_UI','E2E_VAULT_BINDINGS','E2E_TRANSFER_WRITABLE','E2E_HISTORY_WRITABLE','E2E_VAULT_UI','E2E_EXPECT_WORKER']
const missing=required.filter(key=>!process.env[key])
if (missing.length) {console.error(`Missing required E2E variables: ${missing.join(', ')}`);process.exit(2)}
if (process.env.E2E_ALLOW_WRITES!=='1'||process.env.E2E_ISOLATED_STAGING!=='1') {
  console.error('Browser E2E is destructive: E2E_ALLOW_WRITES=1 and E2E_ISOLATED_STAGING=1 required.')
  process.exit(2)
}
for(const key of ['E2E_CONNECTOR_UI','E2E_VAULT_BINDINGS','E2E_TRANSFER_WRITABLE','E2E_HISTORY_WRITABLE','E2E_VAULT_UI','E2E_EXPECT_WORKER']) {
  if(process.env[key]!=='1'){console.error(`Full E2E requires ${key}=1`);process.exit(2)}
}
let url
try {url=new URL(process.env.E2E_BASE_URL)}
catch {console.error('Invalid E2E_BASE_URL');process.exit(2)}
const loopback=['localhost','127.0.0.1','::1','[::1]'].includes(url.hostname)
if (url.username||url.password||url.search||url.hash||url.pathname!=='/'||!['http:','https:'].includes(url.protocol)||(!loopback&&url.protocol!=='https:')) {
  console.error('E2E_BASE_URL must be HTTPS (except localhost), without credentials, query or fragment.')
  process.exit(2)
}
if (url.hostname.startsWith('prod.')||url.hostname.startsWith('production.')) {
  console.error('E2E_BASE_URL must not point at a production hostname.')
  process.exit(2)
}
if(process.env.E2E_CONNECTOR_UI==='1') {
  const host=process.env.E2E_CONNECTOR_FIXTURE_HOST||'', port=Number(process.env.E2E_CONNECTOR_FIXTURE_PORT||8443)
  if(!process.env.E2E_ADMIN_TOTP_SECRET||!host||!(/^[a-f0-9]{64}$/.test(process.env.E2E_CONNECTOR_FIXTURE_PIN||''))||!Number.isInteger(port)||port<1||port>65535) {
    console.error('Connector E2E requires isolated fixture host/port, certificate SHA-256 pin and dedicated admin TOTP seed.');process.exit(2)
  }
}
if(process.env.NODE_TLS_REJECT_UNAUTHORIZED==='0'){console.error('TLS verification cannot be disabled.');process.exit(2)}
try {
  const version=readFileSync(new URL('../../../VERSION',import.meta.url),'utf8').trim()
  const get=async(path,accept)=>{
    const response=await fetch(new URL(path,url),{headers:{Accept:accept},redirect:'error',signal:AbortSignal.timeout(10000)})
    if(!response.ok)throw new Error(`HTTP preflight failed: ${path}`)
    if(!response.headers.get('content-type')?.includes(accept))throw new Error(`Unexpected content type: ${path}`)
    return response
  }
  const system=await (await get('/api/v1/system','application/json')).json()
  if(system.version!==version||system.api_version!=='v1')throw new Error('Staging API version differs from checkout')
  const ready=await (await get('/api/v1/ready','application/json')).json()
  if(ready.status!=='ready')throw new Error('Staging API is not ready')
  const login=await (await get('/login','text/html')).text()
  if(!login.includes('<html'))throw new Error('Staging Admin login is not HTML')
  console.log('E2E preflight PASS — matching API release, readiness and Admin login; disposable staging confirmation supplied.')
}catch(error){console.error('E2E deployment preflight failed:',error instanceof Error?error.message:'unknown error');process.exit(2)}
