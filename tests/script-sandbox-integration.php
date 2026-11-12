<?php
declare(strict_types=1);
/** Real bwrap + cgroup-v2 integration. Requires a dedicated non-root test service and provisioned rootfs. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Script\ScriptRunner;
use Cajeer\Servers\Http\Exception\HttpException;
if(getenv('CAJEER_SCRIPT_SANDBOX_TEST')!=='1'||getenv('SCRIPT_EXECUTION_ENABLED')!=='true'||!getenv('SCRIPT_SANDBOX_ROOTFS')||posix_geteuid()===0){fwrite(STDERR,"REFUSED: provision isolated sandbox and non-root cgroup, set CAJEER_SCRIPT_SANDBOX_TEST=1.\n");exit(2);}
$runner=new ScriptRunner(new ConfigRepository(dirname(__DIR__).'/config'));
$ok=function(bool $value,string $name){if(!$value)throw new RuntimeException('FAIL '.$name);echo "PASS $name\n";};
$run=fn(string $language,string $source,array $parameters=[],int $timeout=10,int $limit=4096,?callable $guard=null)=>$runner->run($source,['provider'=>'local','language'=>$language,'timeout_seconds'=>$timeout,'output_limit_bytes'=>$limit],$parameters,$guard??fn()=>null);
$reject=function(callable $f,string $code)use($ok){try{$f();}catch(HttpException $e){$ok($e->errorCode===$code,$code.' ('.$e->errorCode.')');return;}throw new RuntimeException('Expected '.$code);};
$ok($run('bash','read -r input; printf \'{"bash":true}\'')['output']->bash===true,'real sandbox Bash');
$ok($run('python','import json,sys; print(json.dumps(json.load(sys.stdin)))',['n'=>42])['output']->n===42,'real sandbox Python');
$ok($run('powershell','[Console]::In.ReadToEnd() | ConvertFrom-Json | ConvertTo-Json -Compress',['n'=>42],30)['output']->n===42,'real sandbox PowerShell');
$outside=tempnam(sys_get_temp_dir(),'cajeer-host-only-');file_put_contents($outside,'host-only');
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$err);if(!$server)throw new RuntimeException('test socket failed');$port=(int)substr(strrchr(stream_socket_get_name($server,false),':'),1);putenv('CAJEER_HOST_TEST_SECRET=host-only');
try{
    $source=<<<'PY'
import os,json,sys,socket
p=json.load(sys.stdin)
network=False
try:
    socket.create_connection(('127.0.0.1',p['port']),.5).close();network=True
except OSError: pass
readonly=False
try:
    open('/work/script','w').close()
except OSError: readonly=True
with open('/tmp/test','w') as f:f.write('ok')
print(json.dumps({'host_visible':os.path.exists(p['path']), 'env_visible':bool(os.environ.get('CAJEER_HOST_TEST_SECRET')), 'host_network':network, 'source_readonly':readonly}))
PY;
    $r=$run('python',$source,['path'=>$outside,'port'=>$port])['output'];$ok(!$r->host_visible&&!$r->env_visible&&!$r->host_network&&$r->source_readonly,'host filesystem/env/network isolated; source read-only');
    $reject(fn()=>$run('python','import time;time.sleep(30)',[],1),'script_timeout');
    $reject(fn()=>$run('python','print("x"*8192)',[],10,1024),'script_output_limit');
    $reject(fn()=>$run('python','import time;time.sleep(30)',[],10,4096,static function(){static $n=0;if(++$n>2)throw new HttpException(409,'cancel','script_cancelled');}),'script_cancelled');
}finally{fclose($server);unlink($outside);putenv('CAJEER_HOST_TEST_SECRET');}
