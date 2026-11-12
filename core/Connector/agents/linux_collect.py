# Executed remotely via a fixed SSH command. No writes, no sudo, no user arguments.
import json
import os
import socket
import platform
import subprocess


def read(path):
    with open(path, encoding='utf-8') as f:
        return f.read(65536).strip()


def query(args):
    return json.loads(subprocess.check_output(args, timeout=8, stderr=subprocess.DEVNULL))


identity = read('/etc/machine-id')
release = dict(line.split('=', 1) for line in read('/etc/os-release').splitlines() if '=' in line and not line.startswith('#'))
os_id = release.get('ID', 'linux').strip('"\'')
os_name = release.get('PRETTY_NAME', os_id).strip('"\'')
os_version = release.get('VERSION_ID', platform.release()).strip('"\'')
memory = next(int(line.split()[1]) * 1024 for line in read('/proc/meminfo').splitlines() if line.startswith('MemTotal:'))
disks = [{'name': x['name'], 'size_bytes': int(x['size'])} for x in query(['lsblk', '-J', '-b', '-d', '-o', 'NAME,SIZE,TYPE'])['blockdevices'] if x['type'] == 'disk']
interfaces = []
for x in query(['ip', '-j', 'address', 'show']):
    if x['ifname'] == 'lo':
        continue
    interfaces.append({'name': x['ifname'], 'addresses': [a['local'] for a in x.get('addr_info', []) if a['family'] in ('inet', 'inet6')]})
print(json.dumps({'identity': identity, 'name': socket.gethostname(), 'os_family': os_id if os_id in ('debian', 'ubuntu') else 'linux',
                  'os_name': os_name, 'os_version': os_version, 'cpu_count': os.cpu_count(), 'memory_bytes': memory, 'disks': disks, 'interfaces': interfaces}, separators=(',', ':')))
