import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { once } from 'node:events';
import { resolve, join, relative, isAbsolute } from 'node:path';
import { mkdtempSync, cpSync, rmSync, readdirSync, lstatSync, realpathSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const root = fileURLToPath(new URL('../', import.meta.url));
// Run from a copied standalone bundle outside the checkout, so missing Nitro
// dependencies cannot be hidden by admin/node_modules or workspace resolution.
const source = resolve(process.argv[2] ?? join(root, 'admin/.output'));
const temporary = mkdtempSync(join(tmpdir(), 'cajeer-admin-ssr-'));
const bundle = join(temporary, 'output');
function checkLinks(directory, boundary) {
  for (const name of readdirSync(directory)) {
    const path = join(directory, name);
    const stat = lstatSync(path);
    if (stat.isSymbolicLink()) {
      const target = relative(boundary, realpathSync(path));
      if (target === '..' || target.startsWith('../') || isAbsolute(target)) throw new Error(`Nonportable output symlink: ${path}`);
    } else if (stat.isDirectory()) checkLinks(path, boundary);
  }
}
try {
  checkLinks(source, realpathSync(source));
  cpSync(source, bundle, {recursive:true, verbatimSymlinks:true});
  checkLinks(bundle, realpathSync(bundle));
} catch (error) { rmSync(temporary,{recursive:true,force:true}); throw error; }
const server = createServer();
server.listen(0, '127.0.0.1');
await once(server, 'listening');
const {port} = server.address();
await new Promise((res, rej) => server.close(err => err ? rej(err) : res()));
const child = spawn(process.execPath, [join(bundle, 'server/index.mjs')], {
  cwd: bundle, env: {...process.env, NODE_PATH:'', HOST:'127.0.0.1', PORT:String(port), NITRO_HOST:'127.0.0.1', NITRO_PORT:String(port), NODE_ENV:'production'}, stdio:['ignore','pipe','pipe'],
});
let output = '', failure;
for (const stream of [child.stdout, child.stderr]) stream.on('data', data => { output = (output + data).slice(-16384); });
child.on('error', error => { failure = error; });
const origin = `http://127.0.0.1:${port}`;
try {
  let response;
  const deadline = Date.now() + 20000;
  while (Date.now() < deadline) {
    if (failure || child.exitCode !== null || child.signalCode !== null) throw failure ?? new Error('Nuxt exited before startup');
    try { response = await fetch(`${origin}/login`, {signal:AbortSignal.timeout(1000), redirect:'manual'}); break; } catch { await new Promise(r => setTimeout(r, 150)); }
  }
  assert(response, 'Nuxt SSR startup timed out');
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('x-content-type-options'), 'nosniff');
  const html = await response.text();
  assert.match(html, /Вход в панель/);
  assert.match(html, /id="email"/);
  const asset = html.match(/(?:src|href)="(\/_nuxt\/[^"?]+\.(?:js|css))"/)?.[1];
  assert(asset, 'SSR does not link a compiled client asset');
  const staticResponse = await fetch(origin + asset, {signal:AbortSignal.timeout(5000)});
  assert.equal(staticResponse.status, 200);
  assert((await staticResponse.arrayBuffer()).byteLength > 0, 'Compiled asset is empty');
  console.log('PASS: Nuxt production SSR login, security header and client asset');
} catch (error) {
  console.error(output); throw error;
} finally {
  if (child.pid && child.exitCode === null && child.signalCode === null) {
    child.kill('SIGTERM');
    const kill = setTimeout(() => child.kill('SIGKILL'), 3000);
    await once(child, 'exit'); clearTimeout(kill);
  }
  rmSync(temporary,{recursive:true,force:true});
}
