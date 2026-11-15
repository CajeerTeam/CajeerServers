import {readFileSync} from 'node:fs'
import {pathToFileURL} from 'node:url'

// Minimum complete release suite; exact test counts protect against partial runs/grep.
export const requiredSuites = Object.freeze({
  "admin.spec.ts": 3,
  "catalog.spec.ts": 1,
  "cmdb-integrity.spec.ts": 1,
  "connector-bindings.spec.ts": 1,
  "connector-lifecycle.spec.ts": 1,
  "data-collection.spec.ts": 1,
  "history.spec.ts": 1,
  "import-idempotency.spec.ts": 1,
  "inventory-journey.spec.ts": 1,
  "inventory.spec.ts": 1,
  "ipam.spec.ts": 1,
  "os-network-admin.spec.ts": 1,
  "proxmox-admin.spec.ts": 1,
  "topology.spec.ts": 1,
  "transfer.spec.ts": 1,
  "vault-ui.spec.ts": 3,
  "vmmanager-admin.spec.ts": 1
})
export function verifyReport(report) {
  const counts = Object.fromEntries(Object.keys(requiredSuites).map(f=>[f,0]))
  const ids = new Set()
  const visit=suite=>{
    for(const spec of suite.specs??[]) {
      const file=(spec.file??suite.file??'').split(/[\\/]/).at(-1)
      if(!Object.hasOwn(counts,file))throw new Error(`Unexpected suite: ${file}`)
      if(typeof spec.id!=='string'||!spec.id||ids.has(spec.id))throw new Error(`Missing or duplicate spec identity: ${file}`)
      ids.add(spec.id)
      if(!Array.isArray(spec.tests)||spec.tests.length!==1)throw new Error(`Expected one Chromium test: ${file}`)
      const test=spec.tests[0]
      if(test.expectedStatus!=='passed'||test.status!=='expected'||!Array.isArray(test.results)||test.results.length!==1||test.results[0].status!=='passed'||test.results[0].retry!==0)throw new Error(`E2E did not pass on first execution: ${file}`)
      counts[file]++
    }
    for(const child of suite.suites??[])visit(child)
  }
  if(!Array.isArray(report.suites)||report.stats?.unexpected!==0||report.stats?.flaky!==0||report.stats?.skipped!==0||!Array.isArray(report.errors)||report.errors.length)throw new Error('Invalid or unsuccessful Playwright report')
  report.suites.forEach(visit)
  for(const [file,count] of Object.entries(requiredSuites))if(counts[file]!==count)throw new Error(`Incomplete suite: ${file} (${counts[file]}/${count})`)
  if(report.stats.expected!==Object.values(counts).reduce((a,b)=>a+b,0))throw new Error('Incorrect passed test count')
  return counts
}
if(process.argv[1]&&import.meta.url===pathToFileURL(process.argv[1]).href){
  try {const result=verifyReport(JSON.parse(readFileSync(process.argv[2],'utf8')));console.log('Full browser suites passed:',JSON.stringify(result))}
  catch(e){console.error(e instanceof Error?e.message:'Invalid Playwright report');process.exit(1)}
}
