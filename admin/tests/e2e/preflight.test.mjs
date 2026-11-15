import {test} from 'node:test'
import assert from 'node:assert/strict'
import {createServer} from 'node:http'
import {spawn} from 'node:child_process'
import {fileURLToPath} from 'node:url'
import {readFileSync} from 'node:fs'
const version=readFileSync(new URL('../../../VERSION',import.meta.url),'utf8').trim()
const script=fileURLToPath(new URL('./preflight.mjs',import.meta.url))
async function exercise(mode,overrides={}) {
  const server=createServer((req,res)=>{
    if(req.url==='/api/v1/system'){
      res.setHeader('Content-Type','application/json');res.end(JSON.stringify({version:mode==='version'?'0.0.0':version,api_version:'v1',candidate_id:'a'.repeat(64)}))
    }else if(req.url==='/api/v1/ready'){
      res.setHeader('Content-Type','application/json');res.statusCode=mode==='unready'?503:200;res.end(JSON.stringify({status:mode==='unready'?'not_ready':'ready'}))
    }else if(req.url==='/login'){
      if(mode==='redirect'){res.writeHead(302,{Location:'/'});res.end();return}
      res.setHeader('Content-Type','text/html');res.end('<html><body>Login</body></html>')
    }else{res.writeHead(404);res.end()}
  })
  await new Promise(r=>server.listen(0,'127.0.0.1',r))
  try{
    const env={...process.env,CAJEER_CANDIDATE_ID:'',E2E_BASE_URL:`http://127.0.0.1:${server.address().port}`,E2E_ADMIN_EMAIL:'test@example.invalid',E2E_ADMIN_PASSWORD:'fixture-only',E2E_ADMIN_TOTP_SECRET:'FIXTUREONLY',E2E_CONNECTOR_FIXTURE_HOST:'127.0.0.1',E2E_CONNECTOR_FIXTURE_PIN:'a'.repeat(64),E2E_CONNECTOR_FIXTURE_PORT:'8443',E2E_ALLOW_WRITES:'1',E2E_ISOLATED_STAGING:'1',E2E_CONNECTOR_UI:'1',E2E_VAULT_BINDINGS:'1',E2E_TRANSFER_WRITABLE:'1',E2E_HISTORY_WRITABLE:'1',E2E_VAULT_UI:'1',E2E_EXPECT_WORKER:'1',...overrides}
    const child=spawn(process.execPath,[script],{env,stdio:'ignore'})
    return await new Promise((resolve,reject)=>{child.on('error',reject);child.on('exit',resolve)})
  }finally{await new Promise(r=>server.close(r))}
}
for(const [name,mode,overrides,expected] of [['matching staging','ok',{},0],['matching candidate','ok',{CAJEER_CANDIDATE_ID:'a'.repeat(64)},0],['wrong candidate','ok',{CAJEER_CANDIDATE_ID:'b'.repeat(64)},2],['wrong API version','version',{},2],['unready API','unready',{},2],['redirected login','redirect',{},2],['disabled suite','ok',{E2E_EXPECT_WORKER:'0'},2],['disabled TLS verification','ok',{NODE_TLS_REJECT_UNAUTHORIZED:'0'},2]])test(name,async()=>assert.equal(await exercise(mode,overrides),expected))
