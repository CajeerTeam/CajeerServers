#!/usr/bin/env python3
"""Real loopback HTTP -> PHP runtime -> PostgreSQL -> scheduler/worker -> TLS fixture -> DR.
--smoke exercises the actual HTTP process without database dependencies. Full mode requires
an explicitly disposable PostgreSQL server, Redis readiness and matching pg_dump/pg_restore.
No request mocking, production endpoint, real remote device, or persisted secrets.
"""
import argparse
import base64
import hashlib
import hmac
import http.cookiejar
import http.server
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import signal
import socket
import ssl
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request
import urllib.parse

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or 'php'

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

class Client:
    def __init__(self, origin):
        self.origin = origin
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(),
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def call(self, path, method='GET', body=None, expected=200, headers=None, binary=False):
        if not path.startswith('/') or path.startswith('//'):
            raise RuntimeError('Invalid local API path')
        h = {'Accept': 'application/octet-stream' if binary else 'application/json', 'Origin': self.origin, **(headers or {})}
        data = None
        if body is not None:
            data = json.dumps(body).encode(); h['Content-Type'] = 'application/json'
        request = urllib.request.Request(self.origin+'/api/v1'+path, data=data, headers=h, method=method)
        try:
            response = self.opener.open(request, timeout=30)
        except urllib.error.HTTPError as exc:
            response = exc
        with response:
            raw = response.read(4*1024*1024+1)
            if len(raw)>4*1024*1024: raise RuntimeError('Oversized E2E response')
            if response.status not in ((expected,) if isinstance(expected,int) else expected):
                # Do not emit response bodies, cookies or secret-bearing requests.
                raise RuntimeError(f'{method} {path}: unexpected HTTP {response.status}, request={response.headers.get("X-Request-Id", "none")}')
            if binary: return raw, response.headers
            if not raw: return None
            return json.loads(raw)
    def csrf(self): return self.call('/auth/csrf')['csrf_token']
    def write(self, path, body=None, expected=200, method='POST', headers=None):
        return self.call(path,method,body,expected,{'X-CSRF-Token':self.csrf(),**(headers or {})})


def check(condition, label):
    if not condition: raise RuntimeError('Assertion failed: '+label)
    print('PASS '+label, flush=True)


def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1',0)); return s.getsockname()[1]


def command(argv, cwd, env, timeout=120, decode=False):
    # Bounded subprocess; errors never print raw credentials or environment.
    p = subprocess.run(argv,cwd=cwd,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=timeout)
    if p.returncode: raise RuntimeError(f'Command failed ({argv[1] if len(argv)>1 else argv[0]}): exit {p.returncode}')
    return json.loads(p.stdout) if decode else p.stdout


def private(path, content):
    with path.open('x',encoding='utf-8') as f:
        os.fchmod(f.fileno(),0o600); f.write(content)


def otp(seed, last):
    while int(time.time())//30<=last: time.sleep(.2)
    counter=int(time.time())//30
    # Avoid crossing a boundary while the request is in flight.
    if time.time()%30>27:
        time.sleep(30-time.time()%30+.2);counter=int(time.time())//30
    raw=base64.b32decode(seed+'='*((-len(seed))%8),casefold=True)
    digest=hmac.new(raw,counter.to_bytes(8,'big'),hashlib.sha1).digest(); i=digest[-1]&15
    return str((int.from_bytes(digest[i:i+4],'big')&0x7fffffff)%1000000).zfill(6),counter


def stop(process):
    if process is None: return
    if process.poll() is None:
        process.terminate()
        try: process.wait(timeout=10)
        except subprocess.TimeoutExpired: process.kill(); process.wait(timeout=5)


def main(smoke):
    env={k:v for k,v in os.environ.items() if k in ('PATH','HOME','LANG','LC_ALL','TZ','PG_DUMP_BINARY','PG_RESTORE_BINARY') or k.startswith('CAJEER_PG_TEST')}
    if not smoke:
        if env.get('CAJEER_PG_TEST')!='1' or not re.fullmatch(r'cajeer_test_[a-z0-9_]+',env.get('CAJEER_PG_TEST_DATABASE','')):
            raise RuntimeError('Refused: explicit CAJEER_PG_TEST=1 and cajeer_test_* database required')
        if subprocess.run([PHP,'-r','exit(extension_loaded("pdo_pgsql")?0:2);'],env=env).returncode:
            raise RuntimeError('Full platform E2E requires PHP ext-pdo_pgsql')
    suffix=secrets.token_hex(12); db='cajeer_test_platform_'+suffix; drill='cajeer_drill_platform_'+suffix
    created=[]; process=None; fixture=None; thread=None
    with tempfile.TemporaryDirectory(prefix='cajeer-platform-') as temp:
        temp=Path(temp); app=temp/'app';app.mkdir(mode=0o700)
        for name in ('core','config','routes','public','bin','migrations'):
            shutil.copytree(ROOT/name,app/name)
        for name in ('VERSION','.env.example','release.json'):
            shutil.copy2(ROOT/name,app/name)
        for name in ('app','cache','files','logs','tmp'):
            (app/'storage'/name).mkdir(parents=True,mode=0o700)
        origin='http://127.0.0.1:'+str(port()); env.update(APP_ENV='local',APP_URL=origin,APP_DEBUG='false')
        def cli(*args, decode=False, timeout=120):return command([PHP,'bin/cajeer',*args],app,env,timeout,decode)
        def provision(name):
            command([PHP,str(ROOT/'tests/platform-e2e-db.php'),'create',name],ROOT,env);created.append(name)
        try:
            if not smoke:
                provision(db);provision(drill)
                password='Fixture-'+secrets.token_hex(24);email='platform@example.invalid'
                password_file=temp/'admin-password';private(password_file,password+'\n')
                db_password_file=temp/'db-password';private(db_password_file,env.get('CAJEER_PG_TEST_PASSWORD','')+'\n')
                cli('install','--env=local','--url='+origin,'--db-host='+env.get('CAJEER_PG_TEST_HOST','127.0.0.1'),
                    '--db-port='+env.get('CAJEER_PG_TEST_PORT','5432'),'--db-database='+db,
                    '--db-username='+env.get('CAJEER_PG_TEST_USERNAME','cajeer'),'--db-sslmode='+env.get('CAJEER_PG_TEST_SSLMODE','disable'),
                    '--db-password-file='+str(db_password_file),'--admin-email='+email,'--admin-name=Platform E2E','--admin-password-file='+str(password_file))
                vault=temp/'vault.key'; cli('vault:key:generate','--output='+str(vault))
                env.update(VAULT_MASTER_KEY_FILE=str(vault),QUEUE_CONNECTION='pgsql',REDIS_HOST='127.0.0.1',REDIS_PORT='6379',REDIS_CACHE_PREFIX='cajeer:e2e:'+suffix+':',CONNECTOR_ALLOWED_NETWORKS='127.0.0.1/32',OS_NETWORK_REQUIRE_PROFILE='true',PROXMOX_REQUIRE_PROFILE='true',VMMANAGER_REQUIRE_PROFILE='true')
                check(cli('vault:init','--json',decode=True).get('initialized') is True and cli('vault:check','--json',decode=True).get('ok') is True,'installed Vault initialized')
                # Combined TLS fixture in this process; no external provider address is accepted.
                key=temp/'tls.key'; cert=temp/'tls.crt'
                command(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],ROOT,env)
                pin=hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text())).hexdigest()
                spec=importlib.util.spec_from_file_location('platform_fixture',ROOT/'tests/connector-e2e-fixture.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
                fixture=http.server.ThreadingHTTPServer(('127.0.0.1',0),module.Handler)
                ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);fixture.socket=ctx.wrap_socket(fixture.socket,server_side=True)
                thread=threading.Thread(target=fixture.serve_forever,daemon=True);thread.start()
                env.update(ROUTEROS_CA_FILE=str(cert),PROXMOX_CA_FILE=str(cert),PBS_CA_FILE=str(cert),VMMANAGER_CA_FILE=str(cert))
            with (temp/'http.log').open('wb') as log:
                process=subprocess.Popen([PHP,'-S',origin.removeprefix('http://'),'-t','public','public/index.php'],cwd=app,env=env,stdout=log,stderr=log)
                client=Client(origin); deadline=time.monotonic()+15
                while True:
                    if process.poll() is not None:raise RuntimeError('HTTP process exited')
                    try:client.call('/live');break
                    except (OSError,urllib.error.URLError):
                        if time.monotonic()>deadline:raise
                        time.sleep(.1)
                check(client.call('/live')['status']=='alive','real front controller liveness')
                if smoke:
                    client.call('/auth/me',expected=503)
                    cli('maintenance:enable');client.call('/ready',expected=503);client.call('/live');
                    client.call('/auth/login','POST',{'email':'test@example.invalid','password':'fixture-password-only'},503)
                    check(True,'maintenance blocks real HTTP mutations while liveness remains available')
                    return
                check(client.call('/ready')['status']=='ready','real PostgreSQL / Redis / storage readiness')
                client.call('/admin/session',expected=401)
                client.call('/auth/login','POST',{'email':email,'password':password})
                first=client.csrf(); second=client.csrf();check(first==second,'independent CSRF fetches share session token')
                mfa=client.write('/auth/mfa/enroll',{'password':password},201);code,last=otp(mfa['secret'],-1)
                client.write('/auth/mfa/confirm',{'code':code});check(client.call('/auth/mfa')['enabled'],'MFA enrolled via HTTP')
                ws=next(w for w in client.call('/workspaces')['data'] if w['role']=='owner')
                other=client.write('/workspaces',{'organization_id':ws['organization_id'],'slug':'isolated','name':'Isolated'},201)['id']
                body={'workspace_id':ws['id'],'type':'router','name':'Platform router'}
                client.call('/inventory/assets','POST',body,403)
                asset=client.call('/inventory/assets','POST',body,201,{'X-CSRF-Token':first})['data']
                changed=client.write('/inventory/assets/'+asset['id'],{'name':'Platform router updated'},method='PATCH',headers={'If-Match':asset['etag']})['data']
                client.write('/inventory/assets/'+asset['id'],{'name':'Stale'},412,method='PATCH',headers={'If-Match':asset['etag']})
                history=client.call('/inventory/assets/'+asset['id']+'/history')['data'];check([r['asset_version'] for r in history]==[2,1],'Inventory ETag and revision history')
                listing=client.call('/inventory/assets?workspace_id='+ws['id']+'&limit=1&include_total=0');check(listing['data'][0]['id']==asset['id'],'Inventory page reads persisted asset')
                q='?workspace_id='+ws['id']
                network=client.write('/ipam/networks'+q,{'name':'Test network','cidr':'198.19.0.0/24'},201)['data']
                interface=client.write('/ipam/interfaces'+q,{'asset_id':asset['id'],'name':'e2e0'},201)['data']
                address=client.write('/ipam/addresses'+q,{'network_id':network['id'],'address':'198.19.0.73'},201)['data']
                assigned=client.write('/ipam/addresses/'+address['id']+'/assign'+q,{'interface_id':interface['id']},headers={'If-Match':address['etag']})['data']
                check(assigned['interface_id']==interface['id'] and assigned['status']=='assigned','IPAM network, reservation and interface assignment')
                secret='fixture-secret'; user='reader-'+suffix[:8]
                cred=client.write('/vault/credentials',{'workspace_id':ws['id'],'name':'Router fixture','type':'routeros_api','secret':{'username':user,'password':secret}},201)['data']
                check(secret not in json.dumps(client.call('/vault/credentials/'+cred['id'])),'Vault metadata excludes secret')
                target=client.write('/connectors/targets',{'workspace_id':ws['id'],'provider':'routeros','name':'Router fixture','endpoint_host':'127.0.0.1','endpoint_port':fixture.server_port,'asset_id':asset['id'],'os_network_profile':{'primary_pin':pin,'identity_hash':hashlib.sha256(b'routeros:router01').hexdigest()}},201)['data']
                path='/connectors/targets/'+target['id']
                client.write(path+'/credential-bindings',{'credential_id':cred['id'],'purpose':'api','attach_to_asset':True},(200,201),headers={'If-Match':target['etag']})
                code,last=otp(mfa['secret'],last);client.write('/vault/credentials/'+cred['id']+'/authorize-use',{'asset_id':asset['id'],'purpose':'api','password':password,'totp':code})
                target=client.call(path)['data']
                plan=client.write(path+'/collection-plans',{'name':'backup','collector':'routeros.configuration-backup','operation':'inspect','ttl_seconds':1200,'interval_seconds':60},201,headers={'If-Match':target['etag']})['data']
                deadline=time.monotonic()+100
                while True:
                    cli('connector:tick');cli('connector:work','10')
                    runs=client.call(path+'/runs?plan_id='+plan['id'])['data']
                    if any(r['status']=='succeeded' for r in runs):break
                    if time.monotonic()>deadline:raise RuntimeError('Scheduled collection did not succeed')
                    time.sleep(1)
                client.write(path+'/collection-plans/'+plan['id']+'/state',{'enabled':False})
                check(True,'real Scheduler -> connector queue -> worker -> HTTPS -> persisted backup')
                # Re-enable and immediately dispatch two explicit runs; no scheduler tick during these runs.
                client.write(path+'/collection-plans/'+plan['id']+'/state',{'enabled':True})
                for _ in range(2):
                    idem=secrets.token_hex(16);run=client.write(path+'/collection-plans/'+plan['id']+'/runs',{},202,headers={'Idempotency-Key':idem})['data']
                    again=client.write(path+'/collection-plans/'+plan['id']+'/runs',{},202,headers={'Idempotency-Key':idem})['data'];check(run['id']==again['id'],'manual run idempotency')
                    cli('connector:work','10');check(client.call(path+'/runs/'+run['id'])['data']['status']=='succeeded','worker completed manual backup')
                client.write(path+'/collection-plans/'+plan['id']+'/state',{'enabled':False})
                backups=client.call(path+'/configuration-backups')['data'];check([b['version'] for b in backups]==[2,1],'backup deduplication and changed version')
                export_path=path+'/configuration-backups/'+backups[0]['id']+'/export';content,hdr=client.call(export_path,binary=True)
                check(hashlib.sha256(content).hexdigest()==backups[0]['sha256'] and hdr['X-Artifact-SHA256']==backups[0]['sha256'],'original backup export hash')
                diff=client.call(path+'/configuration-backups/'+backups[0]['id']+'/diff?from='+backups[1]['id'])['data'];check('action=drop' in json.dumps(diff),'actual backup diff')
                # The same connector runtime feeds persistent discovery, topology and metric data.
                code,last=otp(mfa['secret'],last)
                client.write('/vault/credentials/'+cred['id']+'/authorize-use',{'asset_id':asset['id'],'purpose':'api','password':password,'totp':code})
                grant=client.write(path+'/service-grants',{'operation':'inspect','ttl_seconds':1200},201,headers={'If-Match':client.call(path)['data']['etag']})['data']
                for collector in ('routeros.inventory','routeros.network','routeros.metrics'):
                    run=client.write(path+'/runs',{'grant_id':grant['id'],'collector':collector},202,headers={'Idempotency-Key':secrets.token_hex(16)})['data']
                    cli('connector:work','10')
                    check(client.call(path+'/runs/'+run['id'])['data']['status']=='succeeded','persisted '+collector)
                discovery=client.call(path+'/routeros-discovery?scope=inventory')['data']
                check(discovery['state']=='available' and 'router01' in json.dumps(discovery['snapshot']),'native router identity persisted')
                graph=client.call('/inventory/network-discovery'+q)['data']
                check('ether1' in json.dumps(graph),'network discovery feeds workspace topology')
                series=client.call(path+'/metric-series')['data'];check(bool(series),'metric series persisted')
                span=urllib.parse.urlencode({'from':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(time.time()-3600)),'to':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(time.time()+60))})
                points=client.call(path+'/metric-series/'+series[0]['id']+'/samples?'+span)['data']['points'];check(bool(points),'metric samples query returns collected points')
                client.write(path+'/service-grants/'+grant['id'],method='DELETE',expected=(200,204))
                # Real viewer with global permissions, but membership in a different workspace.
                vp='Viewer-'+secrets.token_hex(18);ve='viewer@example.invalid';viewer=client.write('/security/users',{'email':ve,'name':'Viewer','password':vp},201)['id']
                client.write('/security/roles',{'slug':'platform-viewer','name':'Viewer','permissions':['workspaces.read','inventory.assets.read','network.configuration.read','connectors.targets.read']},201)
                client.write('/security/users/'+viewer+'/roles/platform-viewer',expected=(200,204));client.write('/workspaces/'+other+'/members/'+viewer,{'role':'viewer'},204,method='PUT')
                visitor=Client(origin);visitor.call('/auth/login','POST',{'email':ve,'password':vp});visitor.call(export_path,expected=(403,404),headers={'Accept':'application/octet-stream'});visitor.call('/inventory/assets/'+asset['id'],expected=(403,404));check(True,'cross-workspace UUID access denied')
                job=client.write('/admin/jobs',{'type':'storage.probe'},202)['id'];cli('queue:work','--queue=default','--once');check(client.call('/admin/jobs/'+job)['job']['status']=='completed','general queue worker completion')
                check(client.call('/admin/cmdb-integrity')['ok'],'CMDB integrity before backup')
                storage_probe=secrets.token_hex(32);private(app/'storage/files'/'dr-probe.txt',storage_probe)
                backup_dir=temp/'backups';backup_dir.mkdir(mode=0o700);backup_key=temp/'backup.key';cli('backup:key:generate','--output='+str(backup_key),decode=True)
                managed=cli('backup:scheduled','--offline','--directory='+str(backup_dir),'--key-file='+str(backup_key),decode=True,timeout=180)
                restored=cli('backup:drill','--offline','--archive='+str(backup_dir/managed['archive']),'--key-file='+str(backup_key),'--target-db='+drill,'--confirm-target='+drill,'--storage-target='+str(temp/'restored-files'),'--recovery-target='+str(temp/'restored-secrets'),decode=True,timeout=180)
                check(all(restored[k] for k in ('schema_valid','cmdb_valid','app_key_valid','existing_mfa_verified','vault_initialized_and_verified')) and not restored['external_operations_started'],'real pg_dump/pg_restore with MFA and Vault verification')
                check(client.call('/inventory/assets/'+asset['id'])['data']['name']==changed['name'],'source remains intact after recovery drill')
                check((temp/'restored-files'/'dr-probe.txt').read_text()==storage_probe,'recovered storage file exact bytes')
                check(not module.Handler.routeros_files,'remote temporary files cleaned')
                # Boot the restored database with recovered keys and storage; never start restored workers.
                stop(process);process=None
                recovered=temp/'recovered-app';recovered.mkdir(mode=0o700)
                for name in ('core','config','routes','public','bin','migrations'):shutil.copytree(app/name,recovered/name)
                for name in ('VERSION','.env.example','release.json'):shutil.copy2(app/name,recovered/name)
                shutil.copy2(temp/'restored-secrets'/'.env',recovered/'.env')
                for name in ('app','cache','files','logs','tmp'):(recovered/'storage'/name).mkdir(parents=True,mode=0o700)
                recovered_origin='http://127.0.0.1:'+str(port())
                recovered_env={**env,'APP_URL':recovered_origin,'DB_DATABASE':drill,'STORAGE_LOCAL_ROOT':str(temp/'restored-files'),'VAULT_MASTER_KEY_FILE':str(temp/'restored-secrets'/'vault.key'),'APP_KEY':(temp/'restored-secrets'/'app.key').read_text().strip()}
                command([PHP,'bin/cajeer','install:recover'],recovered,recovered_env)
                process=subprocess.Popen([PHP,'-S',recovered_origin.removeprefix('http://'),'-t','public','public/index.php'],cwd=recovered,env=recovered_env,stdout=log,stderr=log)
                recovered_client=Client(recovered_origin); deadline=time.monotonic()+15
                while True:
                    if process.poll() is not None:raise RuntimeError('Recovered HTTP process exited')
                    try:recovered_client.call('/live');break
                    except (OSError,urllib.error.URLError):
                        if time.monotonic()>deadline:raise
                        time.sleep(.1)
                code,last=otp(mfa['secret'],last)
                recovered_client.call('/auth/login','POST',{'email':email,'password':password,'mfa_code':code})
                check(recovered_client.call('/inventory/assets/'+asset['id'])['data']['name']==changed['name'],'recovered API reads original CMDB asset')
                recovered_content,_=recovered_client.call(export_path,binary=True)
                check(recovered_content==content,'recovered API decrypts original configuration backup')
                print('Full platform HTTP E2E PASS',flush=True)
        finally:
            stop(process)
            if fixture is not None:fixture.shutdown();fixture.server_close();thread.join(timeout=5)
            cleanup_failed=False
            for name in reversed(created):
                try:command([PHP,str(ROOT/'tests/platform-e2e-db.php'),'drop',name],ROOT,env)
                except Exception:cleanup_failed=True;print('FAIL cleanup database '+name,flush=True)
            if cleanup_failed:raise RuntimeError('Disposable database cleanup failed')

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--smoke',action='store_true');args=parser.parse_args()
    def interrupted(*_):raise KeyboardInterrupt()
    signal.signal(signal.SIGTERM,interrupted)
    try:main(args.smoke)
    except Exception as exc:print('FAIL platform E2E: '+str(exc),flush=True);raise SystemExit(1)
