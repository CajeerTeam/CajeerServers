#!/usr/bin/env python3
"""Disposable HTTPS Proxmox protocol fixture. No access to a real hypervisor.
Run only on an isolated staging management network. The synthetic token is public.
"""
import argparse
import http.server
import json
import ssl
import threading
import urllib.parse

NODE = 'cajeer-e2e-pve'
VM = 101
AUTH = 'PVEAPIToken=cajeer-e2e@pve!fixture=fixture-only-cajeer-token'

class Handler(http.server.BaseHTTPRequestHandler):
    lock = threading.Lock()
    state = 'stopped'
    tasks = {}
    counter = 0

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

    def do_GET(self):
        if not self.authorized():
            return
        path = urllib.parse.unquote(self.path)
        with self.lock:
            if path == '/api2/json/version':
                self.reply({'version':'9.0.3'})
            elif path == '/api2/json/cluster/status':
                self.reply([{'type':'node','name':NODE,'local':1,'online':1}])
            elif path == f'/api2/json/nodes/{NODE}/qemu/{VM}/config':
                self.reply({'digest':'a'*40})
            elif path == f'/api2/json/nodes/{NODE}/qemu/{VM}/status/current':
                self.reply({'vmid':VM,'status':Handler.state,'qmpstatus':Handler.state,'ha':{'managed':0}})
            elif path == '/api2/json/nodes':
                self.reply([{'node': NODE, 'status': 'online', 'cpu': 0.25, 'mem': 4096, 'maxmem': 8192, 'uptime': 120}])
            elif path == '/api2/json/cluster/resources?type=vm':
                self.reply([{'type': 'qemu', 'node': NODE, 'vmid': VM, 'name': 'cajeer-e2e-vm', 'status': Handler.state}])
            elif path.startswith(f'/api2/json/nodes/{NODE}/tasks/') and path.endswith('/status'):
                task = path.split('/tasks/', 1)[1][:-len('/status')]
                self.reply(self.tasks[task] if task in self.tasks else None, 200 if task in self.tasks else 404)
            else:
                self.reply(None, 404)

    def do_POST(self):
        if not self.authorized():
            return
        prefix = f'/api2/json/nodes/{NODE}/qemu/{VM}/status/'
        if self.path not in (prefix+'start', prefix+'shutdown'):
            self.reply(None, 404)
            return
        # Only this synthetic VM is ever modified, in process memory.
        action = self.path[len(prefix):]
        with self.lock:
            Handler.counter += 1
            Handler.state = 'running' if action == 'start' else 'stopped'
            kind = 'qmstart' if action == 'start' else 'qmshutdown'
            task = f'UPID:{NODE}:{Handler.counter:08X}:00000002:00000003:{kind}:{VM}:cajeer-e2e@pve!fixture:'
            self.tasks[task] = {'status': 'stopped', 'exitstatus': 'OK'}
            self.reply(task)

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
