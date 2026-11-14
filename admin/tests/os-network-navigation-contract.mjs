import assert from 'node:assert/strict'
import {osProvider,osProviders,osNetworkUrl,osViews,osView,scopedOsTarget,sumOsSummaries,isOsSummary,osScopes} from '../composables/osNetworkNavigation.ts'
const a='00000000-0000-4000-8000-000000000001',b='00000000-0000-4000-8000-000000000002'
let n=0;const check=(actual,expected)=>{assert.deepEqual(actual,expected);n++}
for(const p of osProviders)check(osProvider(p),p)
for(const p of ['proxmox','pbs','vmmanager','SSH',['ssh'],null,'../ssh'])check(osProvider(p),null)
const all=()=>true,none=()=>false,viewer=p=>['workspaces.read','connectors.targets.read','inventory.assets.read'].includes(p)
check(osViews('routeros',all),['overview','discovery','metrics','plans','events','backups','topology']);check(osViews('ssh',all),['overview','discovery','metrics','plans','events','topology']);check(osViews('routeros',viewer),['overview','discovery','metrics','plans']);check(osViews('routeros',none),[]);check(osViews(null,all),['overview']);check(osView('backups',osViews('ssh',all)),'overview');check(osView(['backups'],osViews('routeros',all)),'overview');check(osView('backups',osViews('routeros',all)),'backups')
check(osNetworkUrl(a,b,'backups','routeros'),`/os-network?workspace_id=${a}&target_id=${b}&provider=routeros&view=backups`);check(osNetworkUrl(a,'../bad','overview','bad'),`/os-network?workspace_id=${a}`);check(osNetworkUrl(null,b),'/os-network');check(osNetworkUrl(a,undefined,'overview','snmp'),`/os-network?workspace_id=${a}&provider=snmp`)
const t={workspace_id:a,id:b,provider:'routeros'};check(scopedOsTarget(t,a),t);check(scopedOsTarget(t,b),null);check(scopedOsTarget({...t,provider:'proxmox'},a),null);check(scopedOsTarget(null,a),null);check(scopedOsTarget(t,''),null)
const x={total:4,healthy:1,problem:1,disabled:1,unchecked:1,without_profile:2,unlinked:1};check(sumOsSummaries([x,x]),{total:8,healthy:2,problem:2,disabled:2,unchecked:2,without_profile:4,unlinked:2});check(sumOsSummaries([]),{total:0,healthy:0,problem:0,disabled:0,unchecked:0,without_profile:0,unlinked:0});check(x.total,4);check(osScopes.winrm.includes('updates'),true);check(osScopes.routeros.includes('routing'),true)
check(isOsSummary(x),true);check(isOsSummary({...x,total:3}),false);check(isOsSummary({...x,healthy:'1'}),false);check(isOsSummary({...x,unlinked:5}),false);check(isOsSummary(null),false)
console.log(`OS / Network navigation and dashboard policy: ${n} PASS`)
