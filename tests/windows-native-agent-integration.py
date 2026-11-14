#!/usr/bin/env python3
"""Windows PowerShell 5.1/CIM execution, then production PHP validation. No mocked Windows values."""
import base64
import json
import pathlib
import platform
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = r'''
require 'core/autoload.php';
use Cajeer\Servers\Connector\{HostObservation,WindowsDiscovery,OsNetworkProfile};
use Cajeer\Servers\Metrics\HostMetrics;
use Cajeer\Servers\Events\EventDiscovery;
$req=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$d=$req['data'];$scope=$req['scope'];
$identity=str_replace('-','',strtolower($d['identity']));
OsNetworkProfile::verifyHost('winrm',['identity_hash'=>hash('sha256','winrm:'.$identity)],$d['identity']);
if($scope==='collect'){$r=HostObservation::result($d,'winrm','sync');if(!$r->complete||count($r->items)!==1)exit(1);$out=['ok'=>true];}
elseif($scope==='metrics'){$r=HostMetrics::normalize($d,'winrm');if(count($r)<5)exit(1);$out=['ok'=>true];}
elseif($scope==='events'){$r=EventDiscovery::normalize('winrm',$d,$req['previous']);$out=['ok'=>true,'cursor'=>$r['cursor'],'keys'=>array_column($r['events'],'key')];}
else{$r=WindowsDiscovery::normalize($d,$scope);if(!$r['complete'])exit(1);$out=['ok'=>true,'counts'=>$r['counts']];}
echo json_encode($out,JSON_THROW_ON_ERROR);
'''


def run(scope, previous=None):
    source=(ROOT/'core/Connector/agents'/f'windows_{scope}.ps1').read_text(encoding='utf-8')
    if scope=='events':
        cursor=base64.b64encode(json.dumps(previous or {}).encode()).decode()
        source=source.replace('__CURSOR_BASE64__',cursor)
    encoded=base64.b64encode(source.encode('utf-16-le')).decode()
    proc=subprocess.run(['powershell.exe','-NoLogo','-NoProfile','-NonInteractive','-EncodedCommand',encoded],capture_output=True,timeout=90)
    if proc.returncode or proc.stderr:
        raise RuntimeError(f'Native PowerShell {scope} failed (exit {proc.returncode})')
    data=json.loads(proc.stdout.decode('utf-8-sig'))
    checked=subprocess.run(['php','-r',PHP],input=json.dumps({'scope':scope,'data':data,'previous':previous or {}}).encode(),capture_output=True,cwd=ROOT,timeout=30)
    if checked.returncode:
        raise RuntimeError(f'Production normalization of native {scope} failed')
    result=json.loads(checked.stdout)
    assert result['ok']
    return data['identity'],result


if __name__=='__main__':
    if platform.system()!='Windows':
        raise SystemExit('Native Windows integration requires Windows, not a fixture.')
    identity=None
    for scope in ('collect','metrics','system','software','services','storage','network','updates','events'):
        current,result=run(scope)
        if identity is None:identity=current
        assert current==identity
        if scope=='system':assert result['counts']['system']==1
        if scope=='network':assert result['counts']['interfaces']>0
        if scope=='events':
            current,following=run('events',result['cursor'])
            assert current==identity and not set(result['keys'])&set(following['keys'])
        print('PASS native Windows '+scope,flush=True)
