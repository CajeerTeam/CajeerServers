import process from 'node:process'

/** Fail closed before browser tests can modify an infrastructure inventory. */
const required=['E2E_BASE_URL','E2E_ADMIN_EMAIL','E2E_ADMIN_PASSWORD','E2E_ALLOW_WRITES','E2E_ISOLATED_STAGING']
const missing=required.filter(key=>!process.env[key])
if (missing.length) {console.error(`Missing required E2E variables: ${missing.join(', ')}`);process.exit(2)}
if (process.env.E2E_ALLOW_WRITES!=='1'||process.env.E2E_ISOLATED_STAGING!=='1') {
  console.error('Browser E2E is destructive: E2E_ALLOW_WRITES=1 and E2E_ISOLATED_STAGING=1 required.')
  process.exit(2)
}
let url
try {url=new URL(process.env.E2E_BASE_URL)}
catch {console.error('Invalid E2E_BASE_URL');process.exit(2)}
const loopback=['localhost','127.0.0.1','::1','[::1]'].includes(url.hostname)
if (url.username||url.password||url.search||url.hash||!['http:','https:'].includes(url.protocol)||(!loopback&&url.protocol!=='https:')) {
  console.error('E2E_BASE_URL must be HTTPS (except localhost), without credentials, query or fragment.')
  process.exit(2)
}
if (url.hostname.startsWith('prod.')||url.hostname.startsWith('production.')) {
  console.error('E2E_BASE_URL must not point at a production hostname.')
  process.exit(2)
}
if(process.env.E2E_CONNECTOR_UI==='1') {
  const host=process.env.E2E_CONNECTOR_FIXTURE_HOST||'', port=Number(process.env.E2E_CONNECTOR_FIXTURE_PORT||8443)
  if(!process.env.E2E_ADMIN_TOTP_SECRET||!host||!Number.isInteger(port)||port<1||port>65535) {
    console.error('Connector E2E requires isolated fixture host/port and dedicated admin TOTP seed.');process.exit(2)
  }
}
console.log('E2E preflight PASS — explicit disposable staging confirmation supplied. No deployment state was checked.')
