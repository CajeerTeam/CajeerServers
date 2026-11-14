# Fixed read-only Debian/Ubuntu probes. Scope is selected from a local allowlist.
import json
import os
import platform
import re
import socket
import subprocess
import sys
import time
import selectors

LIMIT = 750000
DEADLINE = time.monotonic() + 27


def read(path, optional=False, limit=262144):
    try:
        with open(path, encoding='utf-8') as f:
            value = f.read(limit + 1)
        if len(value) > limit:
            raise ValueError('oversized file')
        return value.strip()
    except (OSError, UnicodeError):
        if optional:
            return None
        raise


def command(args):
    # Bound output and elapsed time, including utilities blocked on a filesystem.
    with subprocess.Popen(args, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                          env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL': 'C', 'SYSTEMD_COLORS': '0', 'SYSTEMD_PAGER': 'cat'}) as p:
        try:
            out = bytearray()
            end = min(DEADLINE, time.monotonic() + 10)
            with selectors.DefaultSelector() as sel:
                sel.register(p.stdout, selectors.EVENT_READ)
                while True:
                    remaining = end - time.monotonic()
                    if remaining <= 0 or not sel.select(remaining):
                        raise TimeoutError()
                    data = os.read(p.stdout.fileno(), 16384)
                    if not data:
                        break
                    out.extend(data)
                    if len(out) > 4 * 1024 * 1024:
                        raise ValueError('oversized command')
            if p.wait(timeout=max(.01, end-time.monotonic())):
                raise RuntimeError('command failed')
            return out.decode('utf-8')
        finally:
            if p.poll() is None:
                p.kill()
                p.wait()


def rows(args):
    return json.loads(command(args))


def size(v):
    return str(int(v)) if v is not None and str(v).isdigit() else None


def system():
    release = dict(line.split('=', 1) for line in read('/etc/os-release').splitlines() if '=' in line and not line.startswith('#'))
    cpu = read('/proc/cpuinfo', limit=4 * 1024 * 1024)
    mem = {line.split(':')[0]: int(line.split()[1])*1024 for line in read('/proc/meminfo').splitlines() if line.startswith(('MemTotal:', 'MemAvailable:', 'SwapTotal:', 'SwapFree:'))}
    model = re.search(r'^(?:model name|Hardware)\s*:\s*(.+)$', cpu, re.M)
    topology = set()
    for block in cpu.split('\n\n'):
        fields = dict((k.strip(), v.strip()) for k,v in (line.split(':',1) for line in block.splitlines() if ':' in line))
        if 'physical id' in fields and 'core id' in fields:
            topology.add((fields['physical id'], fields['core id']))
    return [{'hostname': socket.gethostname(), 'os_id': release.get('ID','linux').strip('"\''), 'os_name': release.get('PRETTY_NAME','Linux').strip('"\''),
             'os_version': release.get('VERSION_ID','').strip('"\''), 'kernel': platform.release(), 'architecture': platform.machine(),
             'cpu_count': str(os.cpu_count() or 1), 'cpu_model': model.group(1) if model else None,
             'cpu_sockets': str(len({a for a,b in topology})) if topology else None, 'cpu_cores': str(len(topology)) if topology else None,
             'memory_bytes': size(mem.get('MemTotal')), 'memory_available_bytes': size(mem.get('MemAvailable')),
             'swap_bytes': size(mem.get('SwapTotal')), 'swap_free_bytes': size(mem.get('SwapFree')),
             'uptime_seconds': str(int(float(read('/proc/uptime').split()[0]))),
             'manufacturer': read('/sys/class/dmi/id/sys_vendor',True), 'model': read('/sys/class/dmi/id/product_name',True)}]


def software():
    result=[]
    for line in command(['dpkg-query','-W','-f=${db:Status-Status}\t${Package}\t${Version}\t${Architecture}\n']).splitlines():
        state,name,version,arch=line.split('\t')
        if state=='installed':
            result.append({'name':name,'version':version,'architecture':arch})
    return sorted(result,key=lambda r:(r['name'],r['architecture']))


def services():
    units={}
    for line in command(['systemctl','list-unit-files','--type=service','--no-legend','--no-pager','--plain']).splitlines():
        parts=line.split()
        if len(parts)>=2 and parts[0].endswith('.service'):
            units[parts[0]]={'name':parts[0],'enabled':parts[1],'load':None,'active':None,'sub':None}
    for line in command(['systemctl','list-units','--type=service','--all','--no-legend','--no-pager','--plain']).splitlines():
        parts=line.split()
        if len(parts)>=4 and parts[0].endswith('.service'):
            row=units.setdefault(parts[0],{'name':parts[0],'enabled':None})
            row.update(load=parts[1],active=parts[2],sub=parts[3])
    return [units[k] for k in sorted(units)]


def storage():
    devices=[]
    # --list prevents duplicate tree entries for multipath devices. No serials or paths to credentials.
    for row in rows(['lsblk','-J','-b','-l','-o','NAME,KNAME,PKNAME,TYPE,SIZE,FSTYPE,RO'])['blockdevices']:
        devices.append({'name':row['name'],'kernel_name':row['kname'],'parent':row.get('pkname'),'type':row['type'],
                        'size_bytes':size(row['size']),'filesystem':row.get('fstype'),'read_only':str(row.get('ro')).lower()})
    # List all mounts, but query capacity only for local filesystems. NFS/CIFS
    # can block on statvfs and are not required for a local capacity snapshot.
    capacities={}
    for line in command(['df','-l','-B1','--output=size,used,avail,target']).splitlines()[1:]:
        parts=line.split(None,3)
        if len(parts)!=4:
            raise ValueError('malformed filesystem capacity')
        total,used,available,target=parts
        capacities[target]=(size(total),size(used),size(available))
    mounts=[]
    for row in rows(['findmnt','-J','-l','-o','TARGET,SOURCE,FSTYPE'])['filesystems']:
        total,used,available=capacities.get(row['target'],(None,None,None))
        # Do not collect mount options, UNC userinfo or network filesystem credentials.
        source=row.get('source')
        if not isinstance(source,str) or not source.startswith('/dev/'):
            source=None
        mounts.append({'mount':row['target'],'source':source,'filesystem':row['fstype'],'size_bytes':total,
                       'used_bytes':used,'available_bytes':available})
    return {'devices':devices,'filesystems':mounts}


def network():
    interfaces=[]
    observed=rows(['ip','-j','address','show'])
    for row in observed:
        interfaces.append({'name':row['ifname'],'index':str(row['ifindex']),'mac':row.get('address'), 'mtu':size(row.get('mtu')),
                           'state':row.get('operstate'),'master':row.get('master')})
    addresses=[]
    for row in observed:
        for addr in row.get('addr_info',[]):
            if addr.get('family') in ('inet','inet6'):
                addresses.append({'interface':row['ifname'],'address':addr['local'],'prefix':str(addr['prefixlen']),'family':addr['family'],'scope':addr.get('scope')})
    routes=[]
    for family in ('-4','-6'):
        for row in rows(['ip','-j',family,'route','show','table','all']):
            routes.append({'family':family[1:], 'destination':row.get('dst','default'),'gateway':row.get('gateway'),'interface':row.get('dev'),
                           'table':str(row.get('table','main')),'metric':size(row.get('metric')),'type':row.get('type')})
    return {'interfaces':interfaces,'addresses':addresses,'routes':routes}


if __name__ == '__main__':
    scope=sys.argv[1]
    probes={'system':lambda:{'system':system()},'software':lambda:{'packages':software()},'services':lambda:{'services':services()},'storage':storage,'network':network}
    identity=read('/etc/machine-id')
    release=read('/etc/os-release')
    if not re.search(r'^ID=["\']?(debian|ubuntu)["\']?$',release,re.M):
        raise ValueError('unsupported distribution')
    data=probes[scope]()
    if identity!=read('/etc/machine-id'):
        raise ValueError('identity changed')
    if any(len(v)>10000 for v in data.values()):
        raise ValueError('too many rows')
    output=json.dumps({'identity':identity,'scope':scope,'sections':data},ensure_ascii=True,separators=(',',':'))
    if len(output)>LIMIT:
        raise ValueError('snapshot too large')
    print(output)
