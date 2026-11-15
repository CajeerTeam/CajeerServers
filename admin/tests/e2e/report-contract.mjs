import assert from 'node:assert/strict'
import {readdirSync,readFileSync} from 'node:fs'
import {requiredSuites,verifyReport} from './verify-report.mjs'
const actual=Object.fromEntries(readdirSync(new URL('.',import.meta.url)).filter(f=>f.endsWith('.spec.ts')).map(f=>[f,(readFileSync(new URL(f,import.meta.url),'utf8').match(/^test\(/gm)||[]).length]))
assert.deepEqual(requiredSuites,actual)
const test={expectedStatus:'passed',status:'expected',results:[{status:'passed',retry:0}]}
const report={stats:{unexpected:0,flaky:0,skipped:0,expected:Object.values(requiredSuites).reduce((a,b)=>a+b,0)},errors:[],suites:Object.entries(requiredSuites).map(([file,count])=>({file,specs:Array.from({length:count},(_,i)=>({id:`${file}-${i}`,tests:[structuredClone(test)]}))}))}
assert.deepEqual(verifyReport(report),requiredSuites)
let checks=2
function reject(change){const copy=structuredClone(report);change(copy);assert.throws(()=>verifyReport(copy));checks++}
for(const status of ['skipped','failed','timedOut','interrupted'])reject(r=>r.suites[0].specs[0].tests[0].results[0].status=status)
reject(r=>r.suites.pop());reject(r=>r.suites[0].specs.pop());reject(r=>r.stats.flaky=1);reject(r=>r.stats.skipped=1)
reject(r=>r.suites[0].specs[0].tests[0].expectedStatus='skipped');reject(r=>r.suites[0].specs[0].tests[0].results.push({status:'passed',retry:1}))
reject(r=>r.suites[0].specs[0].tests[0].results[0].retry=1);reject(r=>r.errors.push({message:'global failure'}));reject(r=>r.stats.expected--)
reject(r=>r.suites[0].specs.push(structuredClone(r.suites[0].specs[0])))
console.log(`Browser release report contract: ${checks} PASS`)
