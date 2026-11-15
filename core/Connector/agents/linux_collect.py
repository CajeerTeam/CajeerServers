# Executed remotely via a fixed SSH command. No writes, no sudo, no user arguments.
import json
import os
import socket
import platform
import subprocess


def read(path):
    with open(path, encoding='utf-8') as f:
        value = f.read(65537)
    if len(value) > 65536:
        raise ValueError('oversized host metadata')
    return value.strip()


def query(args):
    raw = subprocess.check_output(args, timeout=8, stderr=subprocess.DEVNULL,
                                  env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL': 'C'})
    if len(raw) > 262144:
        raise ValueError('oversized host query')
    return json.loads(raw)


def disk_inventory(rows):
    """Physical capacity summary, not the detailed block-device inventory.

    A zero-capacity disk (e.g. an unconfigured zram or a device without
    media) must not invalidate the entire host. Detailed storage discovery
    still reports those devices. Conflicting observations remain errors.
    """
    if not isinstance(rows, list):
        raise ValueError('invalid block-device list')
    disks = {}
    for row in rows:
        if not isinstance(row, dict) or not isinstance(row.get('type'), str):
            raise ValueError('invalid block-device row')
        if row['type'] != 'disk':
            continue
        name, size = row.get('name'), row.get('size')
        if not isinstance(name, str) or not name:
            raise ValueError('invalid disk name')
        if isinstance(size, str) and size.isascii() and size.isdecimal():
            size = int(size)
        if type(size) is not int or not 0 <= size <= 9223372036854775807:
            raise ValueError('invalid disk capacity')
        if name in disks and disks[name] != size:
            raise ValueError('conflicting disk capacities')
        disks[name] = size
    return [{'name': name, 'size_bytes': size} for name, size in sorted(disks.items()) if size > 0]


def collect():
    identity = read('/etc/machine-id')
    release = dict(line.split('=', 1) for line in read('/etc/os-release').splitlines() if '=' in line and not line.startswith('#'))
    os_id = release.get('ID', 'linux').strip('"\'')
    os_name = release.get('PRETTY_NAME', os_id).strip('"\'') or os_id
    os_version = release.get('VERSION_ID', '').strip('"\'') or platform.release()
    memory = next(int(line.split()[1]) * 1024 for line in read('/proc/meminfo').splitlines() if line.startswith('MemTotal:'))
    # Explicit list mode: lsblk tree output may repeat devices under multiple parents.
    disks = disk_inventory(query(['lsblk', '-J', '-b', '-d', '-l', '-o', 'NAME,SIZE,TYPE'])['blockdevices'])
    interfaces = []
    for x in query(['ip', '-j', 'address', 'show']):
        if x['ifname'] == 'lo':
            continue
        interfaces.append({'name': x['ifname'], 'addresses': [a['local'] for a in x.get('addr_info', []) if a['family'] in ('inet', 'inet6')]})
    if identity != read('/etc/machine-id'):
        raise ValueError('identity changed')
    return {'identity': identity, 'name': socket.gethostname(), 'os_family': os_id if os_id in ('debian', 'ubuntu') else 'linux',
            'os_name': os_name, 'os_version': os_version, 'cpu_count': os.cpu_count(), 'memory_bytes': memory, 'disks': disks, 'interfaces': interfaces}


if __name__ == '__main__':
    print(json.dumps(collect(), separators=(',', ':')))
