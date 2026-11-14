import { readFileSync, lstatSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { isDeepStrictEqual } from 'node:util';

// Validate immutable registry inputs before npm runs any dependency lifecycle scripts.
export function verifyNpmLock(root, directory) {
  const read = name => {
    const path = resolve(root, directory, name);
    if (!lstatSync(path).isFile()) throw new Error(`${directory}/${name}: expected regular file`);
    return JSON.parse(readFileSync(path, 'utf8'));
  };
  const pkg = read('package.json'), lock = read('package-lock.json');
  const packages = lock.packages;
  const top = packages?.[''];
  if (lock.lockfileVersion !== 3 || !top || lock.name !== pkg.name || lock.version !== pkg.version || top.name !== pkg.name || top.version !== pkg.version) {
    throw new Error(`${directory}: missing or stale npm v3 lock root`);
  }
  for (const field of ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies', 'engines']) {
    if (!isDeepStrictEqual(top[field] ?? {}, pkg[field] ?? {})) throw new Error(`${directory}: lock differs from manifest (${field})`);
  }
  const direct = Object.keys({...pkg.dependencies, ...pkg.devDependencies, ...pkg.optionalDependencies});
  for (const name of direct) {
    if (!packages[`node_modules/${name}`]) throw new Error(`${directory}: missing direct package ${name}`);
  }
  for (const [path, entry] of Object.entries(packages)) {
    if (path === '') continue;
    if (!/^node_modules\//.test(path) || entry.link || typeof entry.version !== 'string' || !entry.version) throw new Error(`${directory}: invalid package ${path}`);
    // Bundled packages are verified by their containing tarball; ordinary packages must have a registry integrity hash.
    if (entry.inBundle === true) {
      const parent = path.slice(0, path.lastIndexOf('/node_modules/'));
      if (!parent || !packages[parent]?.integrity) throw new Error(`${directory}: unverified bundled package ${path}`);
      continue;
    }
    let url;
    try { url = new URL(entry.resolved); } catch { throw new Error(`${directory}: missing registry URL for ${path}`); }
    if (url.protocol !== 'https:' || url.hostname !== 'registry.npmjs.org' || url.username || url.password || url.port || !/^sha512-[A-Za-z0-9+/]{86}==$/.test(entry.integrity ?? '')) {
      throw new Error(`${directory}: untrusted or unhashed registry package ${path}`);
    }
  }
  return Object.keys(packages).length - 1;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    const root = resolve(process.argv[2] ?? fileURLToPath(new URL('../', import.meta.url)));
    for (const directory of ['admin', 'packages/ts-sdk']) console.log(`${directory}: ${verifyNpmLock(root, directory)} locked packages`);
  } catch (error) { console.error(error.message); process.exitCode = 1; }
}
