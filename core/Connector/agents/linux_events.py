# Fixed read-only probe; cursor is an opaque journalctl argument, never shell input.
import datetime, json, re, subprocess, sys

def identity():
    with open('/etc/machine-id') as f:
        return f.read().strip()

def text(value, limit):
    return value[:limit] if isinstance(value, str) else ''

before = identity()
previous = json.loads(sys.argv[1])
cursor = previous.get('journal')
if cursor is not None and (not isinstance(cursor, str) or not re.fullmatch(r'[A-Za-z0-9=;:_\-]{1,1024}', cursor)):
    raise ValueError('invalid cursor')
cmd = ['journalctl', '--no-pager', '--output=json', '--output-fields=MESSAGE,PRIORITY,SYSLOG_FACILITY,SYSLOG_IDENTIFIER,_HOSTNAME', '--quiet']
if cursor:
    cmd += ['--cursor='+cursor]
else:
    cmd += ['--since=24 hours ago']
p = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
rows = []
more = False
try:
    if cursor:
        line = p.stdout.readline(262145)
        if not line or json.loads(line).get('__CURSOR') != cursor:
            raise ValueError('journal anchor missing')
    for _ in range(101):
        line = p.stdout.readline(262145)
        if not line:
            if p.wait(timeout=5) != 0:
                raise ValueError('journal unavailable')
            break
        if len(line)>262144:
            raise ValueError('event too large')
        r=json.loads(line)
        if len(rows)==100:
            more=True
            break
        key=r['__CURSOR']
        if not re.fullmatch(r'[A-Za-z0-9=;:_\-]{1,1024}',key):
            raise ValueError('invalid journal cursor')
        micros=int(r['__REALTIME_TIMESTAMP'])
        t=datetime.datetime.fromtimestamp(micros//1000000,datetime.timezone.utc).replace(microsecond=micros%1000000)
        priority=r.get('PRIORITY','6'); facility=r.get('SYSLOG_FACILITY')
        rows.append(dict(key=key,channel='journal',event_at=t.isoformat(),severity=int(priority) if str(priority) in '01234567' and len(str(priority))==1 else 6,facility=int(facility) if str(facility).isdigit() and int(facility)<=23 else None,hostname=text(r.get('_HOSTNAME'),255),application=text(r.get('SYSLOG_IDENTIFIER'),255),message=text(r.get('MESSAGE'),1024)))
        cursor=key
finally:
    if p.poll() is None:
        p.terminate();p.wait(timeout=5)
if identity()!=before:
    raise ValueError('identity changed')
print(json.dumps({'identity':before,'events':rows,'cursor':{'journal':cursor} if cursor else {},'more':more},ensure_ascii=False))
