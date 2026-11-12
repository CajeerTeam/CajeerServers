# Fixed read-only Linux collector. No arguments or subprocesses.
import json, os, time

def cpu():
    with open('/proc/stat') as f:
        v = list(map(int, f.readline().split()[1:9]))
    return sum(v), v[3] + v[4]
a, i = cpu()
time.sleep(0.25)
b, j = cpu()
with open('/proc/meminfo') as f:
    mem = {line.split(':')[0]: int(line.split()[1]) * 1024 for line in f if line.startswith(('MemTotal:', 'MemAvailable:'))}
with open('/etc/machine-id') as f:
    identity = f.read(64).strip()
net = []
with open('/proc/net/dev') as f:
    for line in list(f)[2:]:
        name, vals = line.split(':', 1)
        vals = vals.split()
        if name.strip() != 'lo':
            net.append({'name': name.strip(), 'rx': vals[0], 'tx': vals[8]})
fs = os.statvfs('/')
print(json.dumps({'identity': identity, 'cpu_ratio': (b-a-(j-i))/(b-a), 'memory_total': mem['MemTotal'], 'memory_available': mem['MemAvailable'], 'disk_total': fs.f_blocks*fs.f_frsize, 'disk_available': fs.f_bavail*fs.f_frsize, 'network': net}, separators=(',', ':')))
