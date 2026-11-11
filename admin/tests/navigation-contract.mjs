import assert from 'node:assert/strict'
import { inventoryContextUrl, inventoryAssetUrl, queryUuid, selectWorkspace } from '../composables/inventoryNavigation.ts'

const a='00000000-0000-4000-8000-000000000001'
const b='00000000-0000-4000-8000-000000000002'
const wrong='https://example.com/@evil?token=secret'
const spaces=[{id:a,name:'Workspace A'},{id:b,name:'Workspace B'}]
let checks=0
function test(name, value, expected){assert.deepEqual(value,expected,name);checks++}
test('workspace selected by deep link',selectWorkspace(spaces,b),b)
test('missing workspace falls back',selectWorkspace(spaces,undefined),a)
test('inaccessible workspace falls back',selectWorkspace(spaces,'00000000-0000-4000-8000-000000000003'),a)
test('empty workspace list',selectWorkspace([],a),'')
test('valid uuid',queryUuid(b),b)
test('array query rejected',queryUuid([a]),'')
test('malicious workspace rejected',queryUuid(wrong),'')
test('valid navigation with context',inventoryContextUrl('ipam',a,b),`/ipam?workspace_id=${a}&asset_id=${b}`)
test('missing context',inventoryContextUrl('history'),'/history')
test('invalid workspace discarded',inventoryContextUrl('topology',wrong,b),`/topology?asset_id=${b}`)
test('invalid asset discarded',inventoryContextUrl('catalog',a,'../users'),`/catalog?workspace_id=${a}`)
test('asset URL with workspace',inventoryAssetUrl(b,a),`/inventory/${b}?workspace_id=${a}`)
test('asset URL without workspace',inventoryAssetUrl(b),`/inventory/${b}`)
test('invalid asset falls back to list',inventoryAssetUrl(wrong,a),`/inventory?workspace_id=${a}`)
for(const section of ['inventory','ipam','topology','catalog','history','transfer','workspaces','vault']) {
  test(`${section} section URL`,inventoryContextUrl(section,a),`/${section}?workspace_id=${a}`)
}
console.log(`Inventory navigation contract: ${checks} PASS`)
