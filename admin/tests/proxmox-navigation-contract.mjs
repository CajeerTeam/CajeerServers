import assert from 'node:assert/strict'
import {guestIdentity,proxmoxUrl,proxmoxViews,proxmoxView,scopedProxmox} from '../composables/proxmoxNavigation.ts'
const a='00000000-0000-4000-8000-000000000001',b='00000000-0000-4000-8000-000000000002'
let n=0;const check=(actual,expected)=>{assert.deepEqual(actual,expected);n++}
check(guestIdentity('qemu:101'),'qemu:101');check(guestIdentity('lxc:102'),'lxc:102')
for(const g of ['qemu:0','qemu:01','qemu:1/2','lxc:1000000000','vm:101',['qemu:101'],'qemu:1?token=x',null])check(guestIdentity(g),'')
check(proxmoxUrl(a,b,'actions','qemu:101'),`/proxmox?workspace_id=${a}&target_id=${b}&view=actions&guest=qemu%3A101`)
check(proxmoxUrl(a,b,'guests','qemu:101'),`/proxmox?workspace_id=${a}&target_id=${b}&view=guests`)
check(proxmoxUrl(a,'../bad','actions','qemu:101'),`/proxmox?workspace_id=${a}`);check(proxmoxUrl(null,b,'actions','qemu:101'),'/proxmox')
check(proxmoxViews('proxmox',true,true),['overview','guests','storage','tasks','plans','actions'])
check(proxmoxViews('proxmox',true,false),['overview','guests','storage','tasks','plans'])
check(proxmoxViews('proxmox',false,true),[]);check(proxmoxViews('pbs',true,true),['backups','plans']);check(proxmoxViews('ssh',true,true),[])
check(proxmoxView('actions',proxmoxViews('pbs',true,true)),'backups');check(proxmoxView(['actions'],proxmoxViews('proxmox',true,true)),'overview');check(proxmoxView('actions',[]),undefined)
const t={workspace_id:a,provider:'proxmox',id:b};check(scopedProxmox(t,a),t);check(scopedProxmox(t,b),null);check(scopedProxmox({...t,provider:'ssh'},a),null);check(scopedProxmox(null,a),null)
console.log(`Proxmox navigation and permissions: ${n} PASS`)
