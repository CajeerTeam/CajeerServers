<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Script\{ScriptRunner,ScriptExecutionPolicy,ScriptVersion,ScriptTemplate,ScriptExecution};
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Kernel\Application;
$n=0;$ok=function(bool $v,string $label)use(&$n){if(!$v)throw new RuntimeException('FAIL '.$label);$n++;};
$reject=function(callable $f,string $code)use($ok){try{$f();}catch(HttpException $e){$ok($e->errorCode===$code,'reject '.$code.' got '.$e->errorCode);return;}throw new RuntimeException('Expected '.$code);};
$w='10000000-0000-4000-8000-000000000001';$s='10000000-0000-4000-8000-000000000002';$v='10000000-0000-4000-8000-000000000003';$t='10000000-0000-4000-8000-000000000004';
foreach(['bash','python','powershell'] as $lang){$m=ScriptVersion::manifest($w,$s,$v,['language'=>$lang,'provider'=>'local','source'=>'example']);$ok(ScriptExecutionPolicy::channel($m,'example')==='sandbox','local '.$lang);}
foreach((new ScriptTemplate())->all() as $tpl){$m=ScriptVersion::manifest($w,$s,$v,$tpl+['target_ids'=>[$t]]);$ok(ScriptExecutionPolicy::channel($m,$tpl['source'])==='connector','exact template '.$tpl['id']);$reject(fn()=>ScriptExecutionPolicy::channel($m,$tpl['source']."\n"),'script_policy_denied');}
$reject(fn()=>ScriptExecutionPolicy::channel(ScriptVersion::manifest($w,$s,$v,['language'=>'bash','provider'=>'ssh','source'=>'echo unsafe','target_ids'=>[$t]]),'echo unsafe'),'script_policy_denied');
$runner=new ScriptRunner(new ConfigRepository(dirname(__DIR__).'/config'));
// Exercise the actual production duplex I/O function with real trusted local test processes.
// This is NOT a sandbox integration test and never bypasses the production entry point.
$exchange=new ReflectionMethod(ScriptRunner::class,'exchange');
$run=function(array $cmd,int $timeout=3,int $limit=1024,?callable $guard=null,string $input="{}\n")use($runner,$exchange){$pipes=[];$p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['PATH'=>'/usr/bin:/bin']);try{return $exchange->invokeArgs($runner,[$p,&$pipes,$input,$timeout,$limit,$guard??fn()=>null]);}finally{if(proc_get_status($p)['running'])proc_terminate($p,9);foreach($pipes as $f)if(is_resource($f))fclose($f);proc_close($p);}};
$r=$run(['/usr/bin/bash','-c','read -r input; printf \'{"ok":true}\'']);$ok($r['output']->ok===true && $r['exit_code']===0,'real Bash output');
$r=$run(['/usr/bin/python3','-c','import json,sys; print(json.dumps(json.load(sys.stdin)))'],3,1024,null,"{\"n\":42}\n");$ok($r['output']->n===42,'real Python typed stdin');
$reject(fn()=>$run(['/usr/bin/python3','-c','import sys;sys.stdin.read();print("x"*2048)']),'script_output_limit');
$reject(fn()=>$run(['/usr/bin/python3','-c','import sys;sys.stdin.read();print("x"*2048,file=sys.stderr)']),'script_output_limit');
$reject(fn()=>$run(['/usr/bin/python3','-c','import sys;sys.stdin.read();print("not json")']),'script_output_invalid');
$reject(fn()=>$run(['/usr/bin/python3','-c','import sys;sys.stdin.read();print("42")']),'script_output_invalid');
$reject(fn()=>$run(['/usr/bin/python3','-c','import sys;sys.stdin.read();sys.exit(7)']),'script_exit_failed');
$reject(fn()=>$run(['/usr/bin/python3','-c','import time;time.sleep(4)'],1),'script_timeout');
$reject(fn()=>$run(['/usr/bin/python3','-c','import time;time.sleep(4)'],3,1024,static function(){static $n=0;if(++$n>1)throw new HttpException(409,'cancel','script_cancelled');}),'script_cancelled');
$root=dirname(__DIR__);$sql=file_get_contents($root.'/migrations/pgsql/0031_script_execution.sql');$ok(count((new \Cajeer\Servers\Database\SqlStatementParser())->split($sql))===5,'execution migration splits');
$app=(new Application($root))->boot();try{$ok($app->container()->get(ScriptExecution::class) instanceof ScriptExecution,'DI execution service');$routes=$app->container()->get(\Cajeer\Servers\Http\Router::class)->routes();$ok(count(array_filter($routes,fn($r)=>str_contains($r['path'],'/executions')))===4,'four execution routes');}finally{$app->terminate();}
echo "Script execution: $n checks passed (process I/O, policy and wiring; not full sandbox).\n";
