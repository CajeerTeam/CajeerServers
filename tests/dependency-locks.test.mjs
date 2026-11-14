import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtempSync, mkdirSync, rmSync, readFileSync, writeFileSync, symlinkSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {verifyNpmLock} from '../scripts/verify-dependency-locks.mjs';

const root = fileURLToPath(new URL('../', import.meta.url));
const directory = 'packages/ts-sdk';
const fixture = () => {
  const temp = mkdtempSync(join(tmpdir(), 'cajeer-lock-test-'));
  const dir = join(temp,directory);
  mkdirSync(dir,{recursive:true});
  const pkg = JSON.parse(readFileSync(join(root,directory,'package.json'),'utf8'));
  const lock = JSON.parse(readFileSync(join(root,directory,'package-lock.json'),'utf8'));
  const save = () => {writeFileSync(join(dir,'package.json'),JSON.stringify(pkg));writeFileSync(join(dir,'package-lock.json'),JSON.stringify(lock));};
  save();
  return {temp,dir,pkg,lock,save,verify:()=>verifyNpmLock(temp,directory),cleanup:()=>rmSync(temp,{recursive:true,force:true})};
};
test('real SDK lock matches exact manifest and registry package',()=>assert.equal(verifyNpmLock(root,directory),1));
for (const [label,mutate,pattern] of [
  ['stale release', f=>{f.lock.version='0.0.0';}, /stale/],
  ['stale dependencies', f=>{f.pkg.devDependencies.typescript='5.9.0';}, /manifest/],
  ['empty dependency tree', f=>{delete f.lock.packages['node_modules/typescript'];}, /missing direct/],
  ['missing integrity', f=>{delete f.lock.packages['node_modules/typescript'].integrity;}, /unhashed/],
  ['untrusted host', f=>{f.lock.packages['node_modules/typescript'].resolved='https://registry.npmjs.org.evil.example/a.tgz';}, /untrusted/],
  ['local symlink', f=>{f.lock.packages['node_modules/typescript'].link=true;}, /invalid package/],
  ['invalid hash', f=>{f.lock.packages['node_modules/typescript'].integrity='sha512-bogus';}, /unhashed/],
  ['unknown bundle parent', f=>{f.lock.packages['node_modules/typescript'].inBundle=true;}, /unverified bundled/],
]) test(`rejects ${label}`,()=>{const f=fixture();try{mutate(f);f.save();assert.throws(f.verify,pattern);}finally{f.cleanup();}});
test('rejects missing lockfile',()=>{const f=fixture();try{rmSync(join(f.dir,'package-lock.json'));assert.throws(f.verify,/ENOENT/);}finally{f.cleanup();}});
test('rejects symlink lockfile',()=>{const f=fixture();try{rmSync(join(f.dir,'package-lock.json'));symlinkSync(join(root,directory,'package-lock.json'),join(f.dir,'package-lock.json'));assert.throws(f.verify,/regular file/);}finally{f.cleanup();}});
