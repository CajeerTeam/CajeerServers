#!/usr/bin/env python3
"""Real UDP/TCP/mTLS sockets with an isolated recording sink; no database emulation."""
import json, pathlib, socket, ssl, subprocess, tempfile, time
ROOT=pathlib.Path(__file__).resolve().parents[1]
ID='11111111-1111-4111-8111-111111111111'
MSG=b'<34>1 2026-11-14T12:00:00Z claimed-host app - - - message'
def run(*args):
    subprocess.run(args,check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
def port(kind):
    with socket.socket(socket.AF_INET,kind) as s:
        s.bind(('127.0.0.1',0));return s.getsockname()[1]
def wait_rows(path,n):
    deadline=time.monotonic()+5
    while time.monotonic()<deadline:
        if path.exists():
            rows=[json.loads(line) for line in path.read_text().splitlines() if line]
            if len(rows)>=n:return rows
        time.sleep(.05)
    raise AssertionError('Missing events')
with tempfile.TemporaryDirectory() as d:
    tmp=pathlib.Path(d)
    run('openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=CA','-addext','basicConstraints=critical,CA:TRUE','-addext','keyUsage=critical,keyCertSign,cRLSign','-keyout',str(tmp/'ca.key'),'-out',str(tmp/'ca.pem'))
    for name in ['localhost','client','other']:
        run('openssl','req','-new','-newkey','rsa:2048','-nodes','-subj','/CN='+name,'-keyout',str(tmp/(name+'.key')),'-out',str(tmp/(name+'.csr')))
        (tmp/'ext').write_text('subjectAltName=DNS:localhost\nextendedKeyUsage=serverAuth\n' if name=='localhost' else 'extendedKeyUsage=clientAuth\n')
        run('openssl','x509','-req','-in',str(tmp/(name+'.csr')),'-CA',str(tmp/'ca.pem'),'-CAkey',str(tmp/'ca.key'),'-CAcreateserial','-days','1','-extfile',str(tmp/'ext'),'-out',str(tmp/(name+'.pem')))
    import hashlib
    pin=hashlib.sha256(ssl.PEM_cert_to_DER_cert((tmp/'client.pem').read_text())).hexdigest()
    for mode in ['udp','tcp','tls']:
        p=port(socket.SOCK_DGRAM if mode=='udp' else socket.SOCK_STREAM);out=tmp/(mode+'.jsonl');conf=tmp/(mode+'.json')
        source={'target_id':ID,'target_version':1}; cfg={'mode':mode,'bind':'127.0.0.1','port':p,'allow_insecure':mode!='tls','sources':{pin if mode=='tls' else '127.0.0.1':source},'cert':str(tmp/'localhost.pem'),'key':str(tmp/'localhost.key'),'ca':str(tmp/'ca.pem')}
        conf.write_text(json.dumps(cfg));conf.chmod(0o600)
        proc=subprocess.Popen(['php',str(ROOT/'tests/syslog-receiver-fixture.php'),str(conf),str(out)],stderr=subprocess.PIPE)
        try:
            time.sleep(.25);assert proc.poll() is None
            if mode=='udp':
                with socket.socket(socket.AF_INET,socket.SOCK_DGRAM) as s:s.sendto(MSG,('127.0.0.1',p));s.sendto(b'bad',('127.0.0.1',p))
                rows=wait_rows(out,1);assert len(rows)==1
            else:
                if mode=='tls':
                    ctx=ssl.create_default_context(cafile=str(tmp/'ca.pem'));ctx.load_cert_chain(str(tmp/'other.pem'),str(tmp/'other.key'))
                    with ctx.wrap_socket(socket.create_connection(('127.0.0.1',p)),server_hostname='localhost') as s:s.sendall(MSG+b'\n')
                    time.sleep(.1);assert not out.exists(),'unmapped certificate accepted'
                    # An idle raw TCP client must not block another mTLS handshake.
                    slow=socket.create_connection(('127.0.0.1',p));started=time.monotonic()
                    ctx=ssl.create_default_context(cafile=str(tmp/'ca.pem'));ctx.load_cert_chain(str(tmp/'client.pem'),str(tmp/'client.key'))
                    sock=ctx.wrap_socket(socket.create_connection(('127.0.0.1',p),timeout=2),server_hostname='localhost')
                    assert time.monotonic()-started<2;slow.close()
                else:sock=socket.create_connection(('127.0.0.1',p))
                with sock:
                    packet=str(len(MSG)).encode()+b' '+MSG
                    sock.sendall(packet[:4]);time.sleep(.05);sock.sendall(packet[4:]+packet)
                    rows=wait_rows(out,2);assert len(rows)==2
            assert all(r['target']==ID and r['event']['message']=='message' and r['event']['hostname']=='claimed-host' for r in rows)
        finally:
            proc.terminate();proc.wait(timeout=5)
            if proc.returncode not in (-15,0):print(proc.stderr.read().decode())
print('Syslog socket integration: UDP, TCP fragmented frames, mTLS pinned client, rejected unmapped client, nonblocking handshake PASS')
