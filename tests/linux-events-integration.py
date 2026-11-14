#!/usr/bin/env python3
"""Run fixed production journal probe with a controlled journalctl executable."""
import json, pathlib, subprocess, tempfile, os, sys
ROOT=pathlib.Path(__file__).resolve().parents[1];script=ROOT/'core/Connector/agents/linux_events.py'
with tempfile.TemporaryDirectory() as d:
    tmp=pathlib.Path(d);tool=tmp/'journalctl'
    tool.write_text('''#!/usr/bin/env python3
import sys,json
cursor=next((x.split('=',1)[1] for x in sys.argv if x.startswith('--cursor=')),None)
start=int(cursor.split('=')[1]) if cursor else 1
for i in range(start,106):
 print(json.dumps({'__CURSOR':'i='+str(i),'__REALTIME_TIMESTAMP':'1760000000000000','PRIORITY':'4','MESSAGE':'event '+str(i)}))
''');tool.chmod(0o755)
    env=dict(os.environ,PATH=str(tmp)+':'+os.environ['PATH'])
    def probe(cursor):return subprocess.run([sys.executable,str(script),json.dumps(cursor)],env=env,capture_output=True,text=True,timeout=10)
    a=json.loads(probe({}).stdout);assert len(a['events'])==100 and a['cursor']=={'journal':'i=100'} and a['more']
    b=json.loads(probe(a['cursor']).stdout);assert len(b['events'])==5 and b['cursor']=={'journal':'i=105'} and not b['more']
    c=json.loads(probe(b['cursor']).stdout);assert c['events']==[] and c['cursor']==b['cursor']
    assert probe({'journal':'i=999'}).returncode!=0
print('Linux event probe: bounded page, incremental cursor, empty continuation, missing anchor PASS')
