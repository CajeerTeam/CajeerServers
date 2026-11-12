<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Script\{ScriptOutputParser,ScriptVersion,ScriptRunner};
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Inventory\TransferFormat;
$n=0;$ok=function(bool $v,string $s)use(&$n){if(!$v)throw new RuntimeException('FAIL '.$s);$n++;};
$p=new ScriptOutputParser();
$parse=fn(string $raw,string $format='json',string $schema='document')=>$p->parse($raw,['parser'=>$format,'output_schema'=>$schema]);
$bad=function(callable $f)use($ok){try{$f();}catch(HttpException $e){$ok($e->errorCode==='script_output_invalid','reject '.$e->errorCode);return;}throw new RuntimeException('Expected parse rejection');};
$ok($parse('{"ok":true}')['output']->ok===true,'legacy JSON object');
$ok($parse('["'.str_repeat('a',100000).'"]')['output'][0]===str_repeat('a',100000),'long JSON string');
$ok($parse('{"a":"\\u00e9","b":[1,null,false]}')['output']->a==='é','UTF8 and nested JSON');
foreach(['{"a":1,"a":2}','{"a":1,"\\u0061":2}','{"a":1,}','{"a":','[1] trailing','42','{"x":1e999}','{"x":"\\u0000"}',"\xff",str_repeat('[',32).'0'.str_repeat(']',32)] as $s)$bad(fn()=>$parse($s));
$r=$parse("name,value\r\n\"a,b\",\"x\"\"y\"\r\nsecond,\"a\nb\"\r\n",'csv');$ok($r['output'][0]->name==='a,b'&&$r['output'][0]->value==='x"y'&&$r['output'][1]->value==="a\nb",'CSV quote, CRLF and multiline');
foreach(["a,a\n1,2", "a,b\n1", "a\n\"open", "a\n\"x\"oops", "a\nfoo\"bar", "a\rfoo", "a\n".str_repeat("x\n",2001)] as $s)$bad(fn()=>$parse($s,'csv'));
$ok($parse("# comment\nname=a=b\nvalue=\n",'key_value')['output']->name==='a=b','key-value literal');
foreach(["a=1\na=2",'missing',"=x",'# comment'] as $s)$bad(fn()=>$parse($s,'key_value'));
$r=$parse("external_id,type,name,ip_addresses\nserver-1,server,web,192.0.2.1;2001:db8::1\n",'csv','observations');
$o=$r['normalized']['observations'][0];$ok($o['fields']['ip_addresses']===['192.0.2.1','2001:db8::1']&&!$r['normalized']['complete'],'typed observation partial');
$import=json_encode([array_intersect_key($o,array_flip(['external_id','type','fields']))],JSON_THROW_ON_ERROR);$ok(count(TransferFormat::parse('json',$import,'11111111-1111-4111-8111-111111111111'))===1,'existing Inventory import accepts normalized output');
foreach(['{"external_id":"a","type":"server","fields":{"owner_user_id":"x"}}','{"external_id":"a","type":"server","name":"x","workspace_id":"fake"}','{"external_id":"ssh:11111111-1111-4111-8111-111111111111:a","type":"server","name":"x"}','{"external_id":"a","type":"server","fields":{"ip_addresses":["bad"]}}','[{"external_id":"a","type":"server","name":"x"},{"external_id":"a","type":"server","name":"y"}]'] as $s)$bad(fn()=>$parse($s,'json','observations'));
$r=$parse("# HELP cpu Load\n# TYPE cpu gauge\ncpu{zone=\"a\\nB\",cpu=\"0\"} 1.25e1\ncpu{cpu=\"1\",zone=\"a\"} -2\n",'prometheus','metrics');$samples=$r['normalized']['metric_samples'];$ok($samples[0]['value']===12.5&&$samples[0]['labels']->zone==="a\nB"&&$samples[1]['value']===-2,'Prometheus finite values and escaped labels');
$ok($parse("x 1 ".(time()*1000)."\n",'prometheus','metrics')['normalized']['count']===1,'Prometheus millisecond timestamp');
foreach(['x NaN','x +Inf','x 1e999','x{a="1",a="2"} 1',"x{a=\"1\",b=\"2\"} 1\nx{b=\"2\",a=\"1\"} 2",'x 1 1','x{__name__="x"} 1',"# EOF\nx 1",'x 1 # exemplar'] as $s)$bad(fn()=>$parse($s,'prometheus','metrics'));
$ok($parse("metric=system.cpu\nvalue=2.5\nunit=percent\n",'key_value','metrics')['normalized']['metric_samples'][0]['value']===2.5,'key-value numeric metric');
$ok($parse(' {"metric":"load","value":3,"labels":{}} ','json','metrics')['normalized']['metric_samples'][0]['value']===3,'JSON typed numeric metric');
foreach(['{"metric":"x","value":"not numeric"}','{"metric":"x","value":1,"labels":{"x":3}}','{"metric":"x","value":1,"asset_id":"x"}','{"metric":"x","value":1,"observed_at":"2026-02-30T00:00:00Z"}','{"metric":"x","value":1,"observed_at":"2026-11-01T00:00:00+00:99"}'] as $s)$bad(fn()=>$parse($s,'json','metrics'));
$ok($parse('{"event_id":"e1","type":"system.changed","message":"OK"}','json','events')['normalized']['events'][0]['severity']==='info','event normalization');
$bad(fn()=>$parse('[{"event_id":"e1","type":"x","message":"one"},{"event_id":"e1","type":"x","message":"two"}]','json','events'));
$bad(fn()=>$parse('x 1','prometheus','document'));
$bad(fn()=>$p->parse('{"ok":true}',['output_limit_bytes'=>4]));
$bad(fn()=>$p->parse('x=1',['provider'=>'ssh','parser'=>'key_value']));
// Cryptographic policy and backward compatibility: old signing bytes remain untouched.
$w='11111111-1111-4111-8111-111111111111';$s='22222222-2222-4222-8222-222222222222';$v='33333333-3333-4333-8333-333333333333';$src='print(1)';
$m=ScriptVersion::manifest($w,$s,$v,['provider'=>'local','language'=>'python','source'=>$src,'parser'=>'csv','output_schema'=>'metrics']);
$pair=sodium_crypto_sign_keypair();$sk=sodium_crypto_sign_secretkey($pair);$pk=bin2hex(sodium_crypto_sign_publickey($pair));$bytes=ScriptVersion::bytes($m);$sig=bin2hex(sodium_crypto_sign_detached($bytes,$sk));
$ok(ScriptVersion::verify($bytes,$src,$pk,$sig)['parser']==='csv','signed parser');
try{ScriptVersion::verify(ScriptVersion::bytes(array_replace($m,['parser'=>'json'])),$src,$pk,$sig);throw new RuntimeException('signature accepted');}catch(HttpException $e){$ok($e->errorCode==='script_signature_invalid','parser tampering rejected');}
$legacy=$m;$legacy['parser']='json';unset($legacy['output_schema']);$bytes=ScriptVersion::bytes($legacy);$sig=bin2hex(sodium_crypto_sign_detached($bytes,$sk));$ok(ScriptOutputParser::policy(ScriptVersion::verify($bytes,$src,$pk,$sig))===['json','document'],'legacy signatures');
// Invoke the real production duplex I/O and parsing path with trusted subprocesses (not sandbox coverage).
$runner=new ScriptRunner(new ConfigRepository(dirname(__DIR__).'/config'));$exchange=new ReflectionMethod(ScriptRunner::class,'exchange');
$run=function(string $stdout,array $policy)use($runner,$exchange){$pipes=[];$proc=proc_open(['/usr/bin/python3','-c','import sys;sys.stdin.read();sys.stdout.write(sys.argv[1])',$stdout],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);try{return $exchange->invokeArgs($runner,[$proc,&$pipes,"{}\n",3,4096,fn()=>null,$policy]);}finally{if(proc_get_status($proc)['running'])proc_terminate($proc,9);foreach($pipes as $f)if(is_resource($f))fclose($f);proc_close($proc);}};
foreach(['json'=>'{"metric":"x","value":1}','csv'=>"metric,value\nx,1\n",'key_value'=>"metric=x\nvalue=1\n",'prometheus'=>"x 1\n"] as $format=>$raw)$ok($run($raw,['parser'=>$format,'output_schema'=>'metrics'])['normalized']['count']===1,'real subprocess '.$format);
$bad(fn()=>$run('x NaN',['parser'=>'prometheus','output_schema'=>'metrics']));
// Verify the actual registered request validators accept the UI's signed parser policy.
$app=(new \Cajeer\Servers\Kernel\Application(dirname(__DIR__)))->boot();
try {
    $router=$app->container()->get(\Cajeer\Servers\Http\Router::class);
    $registered=(new ReflectionProperty($router,'routes'))->getValue($router);
    $base='/api/v1/workspaces/{workspace}/scripts/{id}/versions';
    $schemaFor=function(string $path)use($registered):array{foreach($registered as $r)if($r['path']===$path&&in_array('POST',$r['methods'],true))return $r['json'];throw new RuntimeException('Missing route');};
    $validate=function(string $path,array $body)use($schemaFor){return \Cajeer\Servers\Http\Validation\JsonInput::parseAndValidate(new \Cajeer\Servers\Runtime\HttpRequest('POST',$path,['Content-Type'=>'application/json'],json_encode($body,JSON_THROW_ON_ERROR)), $schemaFor($path));};
    $ok($validate($base,['language'=>'python','provider'=>'local','source'=>'print(1)','parser'=>'csv','output_schema'=>'metrics'])['parser']==='csv','registered HTTP version schema accepts parser policy');
    $ok($validate($base.'/{version}/validate',['parameters'=>(object)[],'sample_output'=>"metric,value\nx,1"])['sample_output']==="metric,value\nx,1",'registered HTTP preview schema accepts sample');
    foreach ([[$base,['parser'=>'invalid']],[$base.'/{version}/validate',['sample_output'=>[]]]] as [$path,$body]) {try{$validate($path,$body);throw new RuntimeException('Expected request validation rejection');}catch(HttpException $e){$ok($e->status===422&&$e->errorCode==='validation_failed','HTTP request rejects invalid parser policy');}}
} finally {$app->terminate();}
echo "Script output: $n checks passed (parsers, normalization, signed policy, real process I/O).\n";
