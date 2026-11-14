# Fixed read-only Linux collector. No arguments or subprocesses.
import json
import os
import time


def identity():
    with open('/etc/machine-id', encoding='ascii') as f:
        return f.read(64).strip()


def cpu():
    with open('/proc/stat', encoding='ascii') as f:
        v = list(map(int, f.readline().split()[1:9]))
    return sum(v), v[3] + v[4]


def cpu_ratio():
    # A stalled tick or counter discontinuity must not become a zero/negative gauge.
    for _ in range(3):
        a, i = cpu()
        time.sleep(0.25)
        b, j = cpu()
        total, idle = b-a, j-i
        if total > 0 and 0 <= idle <= total:
            return (total-idle)/total
    raise ValueError('CPU counters unavailable')


def collect():
    before = identity()
    ratio = cpu_ratio()
    with open('/proc/meminfo', encoding='ascii') as f:
        mem = {line.split(':')[0]: int(line.split()[1]) * 1024 for line in f if line.startswith(('MemTotal:', 'MemAvailable:'))}
    net = []
    with open('/proc/net/dev', encoding='utf-8') as f:
        for line in list(f)[2:]:
            name, vals = line.split(':', 1)
            vals = vals.split()
            if name.strip() != 'lo':
                net.append({'name': name.strip(), 'rx': vals[0], 'tx': vals[8]})
    fs = os.statvfs('/')
    if identity() != before:
        raise ValueError('identity changed')
    return {'identity': before, 'cpu_ratio': ratio, 'memory_total': mem['MemTotal'], 'memory_available': mem['MemAvailable'], 'disk_total': fs.f_blocks*fs.f_frsize, 'disk_available': fs.f_bavail*fs.f_frsize, 'network': net}


if __name__ == '__main__':
    print(json.dumps(collect(), separators=(',', ':')))
