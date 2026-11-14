import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtempSync,mkdirSync,writeFileSync,rmSync,symlinkSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';
const smoke = fileURLToPath(new URL('../scripts/smoke-admin-build.mjs', import.meta.url));
// Test the smoke harness with real loopback HTTP, not a substitute for running the Nuxt build.
function fixture(assetStatus=200, securityHeader=true) {
  const dir=mkdtempSync(join(tmpdir(),'cajeer-ssr-smoke-test-'));
  mkdirSync(join(dir,'server'));
  writeFileSync(join(dir,'server','index.mjs'), `import {createServer} from 'node:http';
    createServer((req,res)=>{
      if(req.url==='/login'){
        res.writeHead(200,{'Content-Type':'text/html',${securityHeader?"'X-Content-Type-Options':'nosniff'":"'X-Test':'none'"}});
        res.end('<h1>Вход в панель</h1><input id="email"><script src="/_nuxt/client.js"></script>');
      } else if(req.url==='/_nuxt/client.js'){res.writeHead(${assetStatus});res.end('export {}');}
      else {res.writeHead(404);res.end();}
    }).listen(Number(process.env.NITRO_PORT),process.env.NITRO_HOST);`);
  return {dir,run:()=>spawnSync(process.execPath,[smoke,dir],{encoding:'utf8',timeout:10000}),cleanup:()=>rmSync(dir,{recursive:true,force:true})};
}
test('smoke harness starts standalone HTTP bundle and checks compiled asset',()=>{const f=fixture();try{const r=f.run();assert.equal(r.status,0,r.stderr);assert.match(r.stdout,/PASS/);}finally{f.cleanup();}});
test('smoke harness fails if client asset is missing',()=>{const f=fixture(404);try{const r=f.run();assert.equal(r.status,1,r.stderr);assert.match(r.stderr,/404/);}finally{f.cleanup();}});
test('smoke harness fails if security header is missing',()=>{const f=fixture(200,false);try{const r=f.run();assert.equal(r.status,1,r.stderr);assert.match(r.stderr,/nosniff/);}finally{f.cleanup();}});
test('smoke harness rejects escaping output symlink',()=>{const f=fixture();try{symlinkSync(smoke,join(f.dir,'external.mjs'));const r=f.run();assert.equal(r.status,1,r.stderr);assert.match(r.stderr,/Nonportable output symlink/);}finally{f.cleanup();}});
