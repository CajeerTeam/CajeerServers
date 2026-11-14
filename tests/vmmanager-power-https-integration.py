#!/usr/bin/env python3
"""Exercise real pinned PHP transport and VMmanager power driver on an isolated TLS fixture."""
import argparse
import http.server
import json
import pathlib
import re
import ssl
import subprocess
import tempfile
import threading
from urllib.parse import parse_qs, urlsplit

ROOT = pathlib.Path(__file__).resolve().parents[1]

class Handler(http.server.BaseHTTPRequestHandler):
    states = {}
    tasks = {}
    writes = []
    reads = {}
    next_id = 100

    def log_message(self, *_): pass

    def reply(self, value, status=200):
        body = json.dumps(value).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def token(self):
        token = self.headers.get('x-xsrf-token', '')
        assert token.startswith('fixture-power-') and not self.headers.get('Authorization') and not self.headers.get('Cookie')
        return token

    def host(self, token, id):
        key = token, id
        if key not in self.states: self.states[key] = False
        self.reads[key] = self.reads.get(key, 0) + 1
        return dict(id=id, name='guest-'+str(id), state=self.states[key], node={'id': 1 if id==21 else 2},
                    cluster={'id': 1 if id==21 else 2, 'virtualization_type': 'kvm' if id==21 else 'lxd'},
                    account={'id': 7}, cpu_number=2, ram_mib=1024, disk=[{'id': id+100,'size_mib':4096}],
                    iface=[{'id':id+200,'mac':'02:00:00:00:00:01'}], ha_restore_on_fail=token.endswith('-ha'),
                    rescue_mode=token.endswith('-rescue'), **({'lock':'backup'} if token.endswith('-locked') else {})) | (
                    {'name':'replaced-guest'} if token.endswith('-drift') and self.reads[key]>1 else {})

    def do_GET(self):
        token=self.token(); path=urlsplit(self.path); args=parse_qs(path.query)
        if path.path=='/auth/v4/whoami':
            return self.reply({'owner_id':7,'roles':['@user' if token.endswith('-scoped') else '@admin']})
        if path.path=='/auth/v4/self/handles':
            return self.reply({'user_id':7,'handles':{'vm_v3':{'post':[] if token.endswith('-acl') else ['/host/{host_id}/'+x for x in ('start','stop','restart')]}}})
        where=args.get('where',[''])[0]
        if path.path=='/vm/v3/task':
            tasks=[dict(t) for t in self.tasks.get(token,[])]
            # A task is initially running; subsequent explicit status reads complete it.
            match=re.fullmatch(r"\(\(consul_id EQ '([0-9]+)'\) AND \(name EQ '(host_start|host_stop|host_restart)'\)\)",where)
            if match:
                rows=[]
                for t in self.tasks.get(token,[]):
                    if t['consul_id']==int(match[1]) and t['name']==match[2]:
                        if token.endswith('-bad-task'): return self.reply({'list':[dict(t,host={'id':99})]})
                        if token.endswith('-unlinked-task'): return self.reply({'list':[dict(t,host=None,status='complete')]})
                        if token.endswith('-missing-task'): return self.reply({'list':[]})
                        rows.append(dict(t))
                        if t['status']=='running': t['status']='fail' if token.endswith('-failed') else 'complete'
                return self.reply({'list':rows})
            active="((status EQ 'created') OR (status EQ 'running') OR (status EQ 'deferred'))"
            active_page=re.fullmatch(r"\("+re.escape(active)+r" AND \(id GT '([0-9]+)'\)\)",where)
            if where==active or active_page:
                after=int(active_page[1]) if active_page else 0
                if token.endswith('-active-short'):
                    return self.reply({'list':[dict(id=1,consul_id=1,name='host_backup',status='running',host={'id':22})] if after==0 else [dict(id=2,consul_id=2,name='host_backup',status='running',host={'id':21})]})
                if token.endswith('-pending'): tasks.append(dict(id=1,consul_id=1,name='host_backup',status='running',host={'id':21}))
                if token.endswith('-unknown-pending'): tasks.append(dict(id=2,consul_id=2,name='host_backup',status='deferred'))
                return self.reply({'list':[t for t in tasks if t['status'] in ('created','running','deferred') and t['id']>after]})
            if re.fullmatch(r'id GT \d+',where):
                after=int(where.split()[-1]);limit=int(args['limit'][0].split(',')[1]);return self.reply({'list':[t for t in tasks if t['id']>after][:limit]})
            return self.reply({'error':{'code':400}},400)
        m=re.fullmatch(r'id GT (\d+)',where)
        if not m: return self.reply({'error':{'code':400}},400)
        after=int(m[1]);limit=int(args['limit'][0].split(',')[1])
        if path.path=='/vm/v3/node':
            rows=[dict(id=i,name='node-'+str(i),state='active',maintenance_mode=token.endswith('-maintenance'),cluster={'id':i}) for i in (1,2)]
        elif path.path=='/vm/v3/host': rows=[self.host(token,i) for i in (21,22) if i>after]
        else: return self.reply({'error':{'code':404}},404)
        return self.reply({'list':[r for r in rows if r['id']>after][:limit]})

    def do_POST(self):
        token=self.token(); match=re.fullmatch(r'/vm/v3/host/(21|22)/(start|stop|restart)',self.path)
        assert match and self.headers.get('Content-Type')=='application/json' and self.rfile.read(int(self.headers['Content-Length']))==b'{}'
        host=int(match[1]);op=match[2];self.writes.append((token,host,op))
        self.states[token,host]=op!='stop' and not token.endswith('-state-mismatch')
        tasks=self.tasks.setdefault(token,[]);response={'id':host}
        for field,name in [('task','host_stop' if op=='restart' else 'host_'+op)]+([('start_task','host_start')] if op=='restart' else []):
            Handler.next_id+=1;consul=100000+Handler.next_id;tasks.append(dict(id=Handler.next_id,name=name,consul_id=consul,status='running',host={'id':host}));response[field]=consul
        if token.endswith('-lost') or (token=='fixture-power-db' and host==22 and op=='stop'): self.close_connection=True;return
        if token.endswith('-bad-receipt'): response['task']='invalid'
        if token.endswith('-missing-second'): response.pop('start_task',None)
        self.reply(response)

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--postgres',action='store_true');opts=parser.parse_args()
    with tempfile.TemporaryDirectory() as tmp:
        key=pathlib.Path(tmp)/'key.pem';cert=pathlib.Path(tmp)/'cert.pem'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=vmmanager.test','-addext','subjectAltName=DNS:vmmanager.test,IP:127.0.0.1'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
        threading.Thread(target=server.serve_forever,daemon=True).start()
        try:
            subprocess.run(['php',str(ROOT/'tests/vmmanager-power-https.php'),str(server.server_port),str(cert)],check=True,timeout=90)
            assert len(Handler.writes)==16, Handler.writes
            assert all(sum(1 for t,h,o in Handler.writes if t==token and h==host and o==op)==1 for token,host,op in Handler.writes), Handler.writes
            if opts.postgres:
                subprocess.run(['php',str(ROOT/'tests/vmmanager-power-postgres-integration.php'),str(server.server_port),str(cert)],check=True,timeout=180)
                assert [w[1:] for w in Handler.writes if w[0]=='fixture-power-db']==[(21,'start'),(21,'restart'),(21,'stop'),(22,'start'),(22,'stop')], Handler.writes
        finally: server.shutdown();server.server_close()

if __name__=='__main__': main()
