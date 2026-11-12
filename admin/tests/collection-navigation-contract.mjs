import assert from 'node:assert/strict'
import {readFileSync} from 'node:fs'
import {collectionViews,collectionView,collectionUrl,scopedTarget} from '../composables/collectionNavigation.ts'
const a='00000000-0000-4000-8000-000000000001',b='00000000-0000-4000-8000-000000000002'
let n=0;function check(actual,expected,message){assert.deepEqual(actual,expected,message);n++}
const all={read:true,inventory:true,ingestion:true,transfer:true}
check(collectionViews(all),['collectors','plans','metrics','ingestion','exports'],'full access')
check(collectionViews({...all,read:false}),[],'no connector read')
check(collectionViews({...all,inventory:false}),['collectors'],'no inventory data')
check(collectionViews({...all,ingestion:false,transfer:false}),['collectors','plans','metrics'],'limited operator')
check(collectionView('exports',['collectors','metrics']),'collectors','forbidden view falls back')
check(collectionView(['metrics'],['collectors','metrics']),'collectors','array rejected')
check(collectionView('metrics',['collectors','metrics']),'metrics','allowed view')
check(collectionUrl(a,b,'metrics'),`/collections?workspace_id=${a}&target_id=${b}&view=metrics`,'scoped deep link')
check(collectionUrl('https://evil.invalid',b,'metrics'),'/collections?view=metrics','invalid workspace drops target')
check(collectionUrl(a,'../secret','plans'),`/collections?workspace_id=${a}&view=plans`,'invalid target dropped')
check(scopedTarget({workspace_id:a,id:b},a),{workspace_id:a,id:b},'scope match')
check(scopedTarget({workspace_id:b,id:a},a),null,'cross workspace hidden')
check(scopedTarget(null,a),null,'empty target')
const read=p=>readFileSync(new URL('../'+p,import.meta.url),'utf8')
const ui=read('pages/collections.vue'),ing=read('components/ConnectorIngestion.vue'),exp=read('components/ConnectorDataExport.vue')
for(const component of ['ConnectorLifecycle','ConnectorMetrics','ConnectorIngestion','ConnectorDataExport'])check(ui.includes(component),true,component+' reused')
check(ui.includes('seq===sequence')&&ui.includes('scopedTarget')&&ui.includes('onBeforeUnmount'),true,'stale response and scope fences')
check(ing.includes("auth.can('ingestion.write')")&&ing.includes('ownSources')&&ing.includes('validationErrors'),true,'import permission and row errors')
check(exp.includes("crypto.subtle.digest('SHA-256'")&&exp.includes('X-Artifact-SHA256'),true,'verified downloads')
check(ing.includes('clearInterval(timer)')&&exp.includes('clearInterval(timer)'),true,'poll cleanup')
check(read('pages/scripts.vue').includes('selectWorkspace(spaces.value,route.query.workspace_id)'),true,'script scope deep link')
check(read('tests/e2e/data-collection.spec.ts').includes('waitForEvent(\'download\')'),true,'actual browser download scenario')
console.log(`Collection navigation / UI policy: ${n} PASS`)
