import assert from 'node:assert/strict'
import {verifyReport} from './verify-report.mjs'
const test={expectedStatus:'passed',status:'expected',results:[{status:'passed'}]}
const report={stats:{unexpected:0,flaky:0},suites:[{file:'data-collection.spec.ts',specs:[{tests:[test]}]},{file:'connector-lifecycle.spec.ts',specs:[{tests:[test]}]},{file:'proxmox-admin.spec.ts',specs:[{tests:[test]}]}]}
assert.deepEqual(verifyReport(report),{'data-collection.spec.ts':1,'connector-lifecycle.spec.ts':1,'proxmox-admin.spec.ts':1})
for(const status of ['skipped','failed','timedOut','interrupted']){
 const r=structuredClone(report);r.suites[0].specs[0].tests[0].results[0].status=status;assert.throws(()=>verifyReport(r))
}
const missing=structuredClone(report);missing.suites.pop();assert.throws(()=>verifyReport(missing))
const flaky=structuredClone(report);flaky.stats.flaky=1;assert.throws(()=>verifyReport(flaky))
const skipped=structuredClone(report);skipped.suites[0].specs[0].tests[0].expectedStatus='skipped';assert.throws(()=>verifyReport(skipped))
console.log('Browser release report contract: 8 PASS')
