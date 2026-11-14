#!/usr/bin/env python3
"""Disposable HTTPS PVE/PBS/VMmanager protocol fixture. No access to a real hypervisor.
Run only on an isolated staging management network. The synthetic token is public.
"""
import argparse
import http.server
import json
import ssl
import threading
import time
import re
import urllib.parse

NODE = 'cajeer-e2e-pve'
VM = 101
AUTH = 'PVEAPIToken=cajeer-e2e@pve!fixture=fixture-only-cajeer-token'
PBS_AUTH = 'PBSAPIToken=cajeer-e2e@pbs!fixture:fixture-only-pbs-token'

class Handler(http.server.BaseHTTPRequestHandler):
    lock = threading.Lock()
    state = 'stopped'
    tasks = {}
    counter = 0
    snapshots = {}
    vmm_states = {}
    vmm_tasks = {}
    vmm_counter = 1000

    def log_message(self, *_):
        pass

    def reply(self, data, status=200):
        body = json.dumps({'data': data}).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def authorized(self):
        if self.headers.get('Authorization') != AUTH:
            self.reply(None, 401)
            return False
        return True

    def pbs_get(self):
        base='/api2/json/admin/datastore/e2e-store'
        now=int(time.time())-60
        job={'id':'e2e-job','store':'e2e-store','schedule':'daily','last-run-state':'OK','last-run-endtime':now}
        data={
            '/api2/json/version':{'version':'4.1.1'},
            '/api2/json/admin/datastore':[{'store':'e2e-store'}],
            '/api2/json/admin/sync':[dict(job,remote='offsite',**{'remote-store':'remote'})],
            '/api2/json/admin/verify':[job], '/api2/json/admin/prune':[dict(job,**{'keep-last':7})],
            base+'/status':{'total':107374182400,'used':1073741824,'avail':106300440576},
            base+'/gc':{'store':'e2e-store','schedule':'daily','last-run-state':'OK','removed-bytes':20},
            base+'/namespace?max-depth=7':[{'ns':''},{'ns':'e2e'}],
        }
        for q in ['', '?ns=e2e']:
            data[base+'/groups'+q]=[{'backup-type':'vm','backup-id':'101','last-backup':now,'backup-count':1}]
            data[base+'/snapshots'+q]=[{'backup-type':'vm','backup-id':'101','backup-time':now,'size':1073741824,'protected':True,'verification':{'state':'ok'}}]
        self.reply(data.get(self.path),200 if self.path in data else 404)

    def vmm_token(self):
        token=self.headers.get('x-xsrf-token','')
        return token if re.fullmatch(r'fixture-vmm-e2e-[a-f0-9]{32}',token) and not self.headers.get('Authorization') else None

    def vmm_reply(self, value, status=200):
        body=json.dumps(value).encode()
        self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)

    def vmm_get(self, token):
        url=urllib.parse.urlsplit(self.path);q=urllib.parse.parse_qs(url.query);where=q.get('where',[''])[0]
        if url.path=='/auth/v4/whoami':return self.vmm_reply({'owner_id':7,'roles':['@admin']})
        if url.path=='/auth/v4/self/handles':return self.vmm_reply({'user_id':7,'handles':{'vm_v3':{'post':['/host/{host_id}/'+x for x in ('start','stop','restart')]}}})
        guests=[dict(id=i,name='cajeer-e2e-vmm-'+str(i),state=self.vmm_states.get((token,i),False),node={'id':i-20},cluster={'id':i-20,'virtualization_type':'kvm' if i==21 else 'lxd'},account={'id':7},cpu_number=2,ram_mib=1024,hdd_mib=4096,os={'id':1,'name':'E2E Linux'},disk=[{'id':i+100,'size_mib':4096,'storage':{'id':1}}],iface=[{'id':i+200,'mac':f'02:00:00:00:00:{i:02x}','ip':[{'name':f'192.0.2.{i}'}]}],ha_restore_on_fail=False,rescue_mode=False) for i in (21,22)]
        catalog={
            '/vm/v3/cluster':[{'id':1,'name':'E2E KVM','virtualization_type':'kvm'},{'id':2,'name':'E2E LXD','virtualization_type':'lxd'}],
            '/vm/v3/node':[{'id':i,'name':'cajeer-e2e-vmm-node-'+str(i),'state':'active','cluster':{'id':i},'maintenance_mode':False,'cpu_number':8,'ram_mib':{'total':8192,'used':1024}} for i in (1,2)],
            '/vm/v3/host':guests,'/vm/v3/storage':[{'id':1,'name':'E2E storage','type':'lvm','shared':True,'clusters':[1,2]}],
            '/vm/v3/os':[{'id':1,'name':'E2E Linux'}],'/vm/v3/preset':[{'id':1,'name':'E2E preset','cpu_number':2}],'/vm/v3/image':[{'id':1,'name':'E2E image'}],'/vm/v3/os/iso':[{'id':1,'name':'E2E ISO'}],
            '/ip/v3/ipnet':[{'id':1,'name':'192.0.2.0/24','userspace':1,'gateway':'192.0.2.1'}],'/ip/v3/ippool':[{'id':1,'name':'E2E pool','userspace':1,'ipnets':[{'id':1}]}],'/ip/v3/ip':[{'id':i-20,'name':f'192.0.2.{i}','ipnet':1,'ippool':1,'userspace':1,'status':'busy'} for i in (21,22)],
            '/vm/v3/task':self.vmm_tasks.get(token,[]),
        }
        if url.path not in catalog:return self.vmm_reply({'error':{'code':404}},404)
        rows=catalog[url.path]
        match=re.fullmatch(r"\(\(consul_id EQ '([0-9]+)'\) AND \(name EQ '(host_start|host_stop|host_restart)'\)\)",where)
        if url.path=='/vm/v3/task' and match:return self.vmm_reply({'list':[t for t in rows if t['consul_id']==int(match[1]) and t['name']==match[2]]})
        active="((status EQ 'created') OR (status EQ 'running') OR (status EQ 'deferred'))"
        if url.path=='/vm/v3/task' and where==active:return self.vmm_reply({'list':[]})
        m=re.fullmatch(r'id GT ([0-9]+)',where)
        if not m:return self.vmm_reply({'error':{'code':400}},400)
        limit=int(q.get('limit',['0,100'])[0].split(',')[1]);return self.vmm_reply({'list':[r for r in rows if r['id']>int(m[1])][:limit]})

    def vmm_post(self, token):
        match=re.fullmatch(r'/vm/v3/host/(21|22)/(start|stop|restart)',self.path)
        length=int(self.headers.get('Content-Length','0'))
        if not match or length!=2 or self.headers.get('Content-Type')!='application/json' or self.rfile.read(length)!=b'{}':return self.vmm_reply({'error':{'code':400}},400)
        host=int(match[1]);op=match[2];self.vmm_states[token,host]=op!='stop';response={'id':host}
        for field,name in [('task','host_stop' if op=='restart' else 'host_'+op)]+([('start_task','host_start')] if op=='restart' else []):
            Handler.vmm_counter+=1;consul=100000+Handler.vmm_counter;self.vmm_tasks.setdefault(token,[]).append(dict(id=Handler.vmm_counter,consul_id=consul,name=name,status='complete',host={'id':host}));response[field]=consul
        self.vmm_reply(response)

    def do_GET(self):
        if self.vmm_token():
            with self.lock: return self.vmm_get(self.vmm_token())
        if self.headers.get('Authorization') == PBS_AUTH:
            self.pbs_get()
            return
        if not self.authorized():
            return
        path = urllib.parse.unquote(self.path)
        with self.lock:
            if path == '/api2/json/version':
                self.reply({'version':'9.0.3'})
            elif path == '/api2/json/cluster/status':
                self.reply([{'type':'node','name':NODE,'local':1,'online':1}])
            elif path == f'/api2/json/nodes/{NODE}/qemu/{VM}/config':
                self.reply({'digest':'a'*40,'cores':2,'memory':2048,'scsi0':'local-lvm:vm-101-disk-0,size=8G','net0':'virtio=02:00:00:00:00:01,bridge=vmbr0'})
            elif path == f'/api2/json/nodes/{NODE}/qemu/{VM}/status/current':
                self.reply({'vmid':VM,'status':Handler.state,'qmpstatus':Handler.state,'ha':{'managed':0},'cpu':0.25,'mem':1073741824,'maxmem':2147483648})
            elif path == '/api2/json/nodes':
                self.reply([{'node': NODE, 'status': 'online', 'cpu': 0.25, 'mem': 4096, 'maxmem': 8192, 'uptime': 120}])
            elif path == f'/api2/json/nodes/{NODE}/status':
                self.reply({'pveversion':'pve-manager/9.0.3/test','cpuinfo':{'cpus':4,'cores':4,'sockets':1,'model':'E2E CPU'},'memory':{'total':8589934592,'used':1073741824},'uptime':120,'rootfs':{'total':10737418240,'used':1073741824},'swap':{'total':0,'used':0}})
            elif path == '/api2/json/access/permissions':
                self.reply({'/':{'Sys.Audit':1}})
            elif path == '/api2/json/storage':
                self.reply([{'storage':'local-lvm','type':'lvmthin','content':'images,rootdir'}])
            elif path == f'/api2/json/nodes/{NODE}/storage':
                self.reply([{'storage':'local-lvm','type':'lvmthin','active':1,'enabled':1,'shared':0,'total':107374182400,'used':8589934592,'avail':98884247808}])
            elif path == f'/api2/json/nodes/{NODE}/storage/local-lvm/content':
                self.reply([{'volid':'local-lvm:vm-101-disk-0','size':8589934592,'format':'raw','vmid':101,'content':'images'}])
            elif path == f'/api2/json/nodes/{NODE}/network?type=include_sdn':
                self.reply([{'iface':'vmbr0','type':'bridge','bridge_ports':'eno1'},{'iface':'eno1','type':'eth'}])
            elif path == f'/api2/json/nodes/{NODE}/qemu/{VM}/snapshot':
                self.reply([*self.snapshots.values(),{'name':'current'}])
            elif path.startswith(f'/api2/json/nodes/{NODE}/tasks?'):
                q=urllib.parse.parse_qs(urllib.parse.urlsplit(path).query)
                rows=[]
                if q.get('source') != ['active']:
                    since=int(q.get('since',['0'])[0]);until=int(q.get('until',[str(2**31-1)])[0])
                    rows=[r for r in reversed(list(self.tasks.values())) if since<=r['starttime']<=until]
                start=int(q.get('start',['0'])[0]);limit=int(q.get('limit',['50'])[0]);self.reply(rows[start:start+limit])
            elif path == '/api2/json/cluster/resources?type=vm':
                self.reply([{'type': 'qemu', 'node': NODE, 'vmid': VM, 'name': 'cajeer-e2e-vm', 'status': Handler.state}])
            elif path.startswith(f'/api2/json/nodes/{NODE}/tasks/') and path.endswith('/status'):
                task = path.split('/tasks/', 1)[1][:-len('/status')]
                self.reply(dict(self.tasks[task],status='stopped') if task in self.tasks else None, 200 if task in self.tasks else 404)
            else:
                self.reply(None, 404)

    def task(self, kind):
        Handler.counter += 1
        now=int(time.time())
        task = f'UPID:{NODE}:{Handler.counter:08X}:00000002:{now:08X}:{kind}:{VM}:cajeer-e2e@pve!fixture:'
        self.tasks[task] = {'upid':task,'node':NODE,'type':kind,'id':str(VM),'user':'cajeer-e2e@pve!fixture','starttime':now,'endtime':now,'status':'OK','exitstatus':'OK'}
        self.reply(task)

    def do_POST(self):
        if self.vmm_token():
            with self.lock: return self.vmm_post(self.vmm_token())
        if not self.authorized():return
        prefix = f'/api2/json/nodes/{NODE}/qemu/{VM}'
        with self.lock:
            if self.path in (prefix+'/status/start', prefix+'/status/shutdown'):
                action=self.path.rsplit('/',1)[1]
                Handler.state = 'running' if action == 'start' else 'stopped'
                self.task('qmstart' if action == 'start' else 'qmshutdown')
            elif self.path==prefix+'/snapshot':
                length=int(self.headers.get('Content-Length','0'))
                if length>1024:self.reply(None,400);return
                params=urllib.parse.parse_qs(self.rfile.read(length).decode())
                name=params.get('snapname',[''])[0]
                if not re.fullmatch(r'e2e_[a-f0-9]{8}',name) or name in self.snapshots or set(params)!={'snapname'}:
                    self.reply(None,400);return
                self.snapshots[name]={'name':name,'snaptime':int(time.time()),'description':'Synthetic E2E snapshot'}
                self.task('qmsnapshot')
            else:self.reply(None,404)

    def do_DELETE(self):
        if not self.authorized():return
        prefix=f'/api2/json/nodes/{NODE}/qemu/{VM}/snapshot/'
        name=self.path.removeprefix(prefix)
        with self.lock:
            if not self.path.startswith(prefix) or name not in self.snapshots:self.reply(None,404);return
            del self.snapshots[name];self.task('qmdelsnapshot')

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--bind', default='127.0.0.1')
    parser.add_argument('--port', type=int, default=8443)
    parser.add_argument('--cert', required=True)
    parser.add_argument('--key', required=True)
    args = parser.parse_args()
    server = http.server.ThreadingHTTPServer((args.bind, args.port), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(args.cert, args.key)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    print(f'Disposable connector fixture ready on {args.bind}:{server.server_port}', flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
