import {readFileSync} from 'node:fs'
import {pathToFileURL} from 'node:url'

/** Playwright JSON reporter: missing, skipped, flaky or failed critical tests cannot pass. */
export function verifyReport(report) {
  const required=new Map([['data-collection.spec.ts',0],['connector-lifecycle.spec.ts',0]])
  const visit=suite=>{
    for(const spec of suite.specs??[]) {
      const file=(spec.file??suite.file??'').split(/[\\/]/).at(-1)
      if(!required.has(file))continue
      if(!Array.isArray(spec.tests)||!spec.tests.length)throw new Error(`No test results: ${file}`)
      for(const test of spec.tests){
        if(test.expectedStatus!=='passed'||test.status!=='expected'||!Array.isArray(test.results)||!test.results.length||test.results.some(r=>r.status!=='passed'))throw new Error(`Critical E2E did not pass: ${file}`)
        required.set(file,required.get(file)+1)
      }
    }
    for(const child of suite.suites??[])visit(child)
  }
  if(!Array.isArray(report.suites)||report.stats?.unexpected!==0||report.stats?.flaky!==0)throw new Error('Invalid or unsuccessful Playwright report')
  report.suites.forEach(visit)
  for(const [file,count] of required)if(count===0)throw new Error(`Missing executed critical suite: ${file}`)
  return Object.fromEntries(required)
}
if(process.argv[1]&&import.meta.url===pathToFileURL(process.argv[1]).href){
  try {const result=verifyReport(JSON.parse(readFileSync(process.argv[2],'utf8')));console.log('Critical browser suites passed:',JSON.stringify(result))}
  catch(e){console.error(e instanceof Error?e.message:'Invalid Playwright report');process.exit(1)}
}
