import assert from 'node:assert/strict'
import {guestIdentity,vmmanagerViews,vmmanagerView,scopedVmmanager,vmmanagerUrl} from '../composables/vmmanagerNavigation.ts'
import {freshness,planState,syncCollectors} from '../composables/vmmanagerSync.ts'
const w='123e4567-e89b-42d3-a456-426614174000',t='123e4567-e89b-42d3-a456-426614174001'
let n=0;const eq=(a,b)=>{assert.deepEqual(a,b);n++}
for(const invalid of ['host:0','host:2147483648','host:01','host:1/../../','qemu:21',['host:21'],null])eq(guestIdentity(invalid),'')
eq(guestIdentity('host:2147483647'),'host:2147483647');eq(guestIdentity('host:21'),'host:21')
eq(scopedVmmanager({workspace_id:w,provider:'pbs'},w),null);eq(scopedVmmanager({workspace_id:t,provider:'vmmanager'},w),null);eq(scopedVmmanager({workspace_id:w,provider:'vmmanager'},w)?.provider,'vmmanager')
eq(vmmanagerViews('proxmox',true,true,true),[]);eq(vmmanagerViews('vmmanager',false,true,true),[]);eq(vmmanagerViews('vmmanager',true,false,false).includes('actions'),false);eq(vmmanagerViews('vmmanager',true,false,false).includes('network'),false);eq(vmmanagerViews('vmmanager',true,true,true).includes('actions'),true)
eq(vmmanagerView('actions',vmmanagerViews('vmmanager',true,false,false)),'overview');eq(vmmanagerUrl(w,t,'actions','host:21'),`/vmmanager?workspace_id=${w}&target_id=${t}&view=actions&guest=host%3A21`);eq(vmmanagerUrl(w,t,'guests','host:21'),`/vmmanager?workspace_id=${w}&target_id=${t}&view=guests`);eq(vmmanagerUrl('bad',t,'actions','host:21'),'/vmmanager')
const now=Date.parse('2026-11-14T10:00:00Z'),o={state:'available',observed_at:'2026-11-14T09:58:00Z',snapshot:{complete:true}}
eq(freshness(o,now,180),'fresh');eq(freshness(o,now,60),'overdue');eq(freshness({...o,snapshot:{complete:false}},now,180),'partial');eq(freshness({...o,state:'stale'},now,180),'stale');eq(freshness({...o,observed_at:null},now,180),'unknown');eq(freshness({...o,observed_at:'2027-01-01'},now,180),'unknown');eq(freshness(null,now,180),'unknown');eq(freshness({...o,summary:{visibility:'restricted'}},now,180),'partial')
const p={enabled:true,revoked_at:null,expires_at:'2026-11-15',next_due_at:null};eq(planState(p,now),'scheduled');eq(planState({...p,enabled:false},now),'paused');eq(planState({...p,expires_at:'2026-01-01'},now),'expired');eq(planState({...p,revoked_at:'2026-11-14'},now),'revoked');eq(syncCollectors.length,8)
console.log(`VMmanager navigation and dashboard policy: ${n} PASS`)
