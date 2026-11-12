<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Script\{ScriptVersion,ScriptTemplate};
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Kernel\Application;
$n=0;$ok=function(bool $b,string $label)use(&$n){if(!$b)throw new RuntimeException('FAIL '.$label);$n++;};
$reject=function(callable $f)use($ok){try{$f();}catch(HttpException $e){$ok($e->status===422,'invalid rejected');return;}throw new RuntimeException('Expected rejection');};
$w='10000000-0000-4000-8000-000000000001';$s='10000000-0000-4000-8000-000000000002';$v='10000000-0000-4000-8000-000000000003';$t='10000000-0000-4000-8000-000000000004';
$input=['language'=>'python','provider'=>'local','source'=>'print("hello")','parameters'=>[['name'=>'limit','type'=>'integer','min'=>1,'max'=>10],['name'=>'mode','type'=>'string','choices'=>['a','b'],'required'=>false],['name'=>'enabled','type'=>'boolean']]];
$m=ScriptVersion::manifest($w,$s,$v,$input);$bytes=ScriptVersion::bytes($m);$pair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($pair);$pub=bin2hex(sodium_crypto_sign_publickey($pair));$sig=bin2hex(sodium_crypto_sign_detached($bytes,$secret));
$ok(ScriptVersion::verify($bytes,$input['source'],$pub,$sig)===$m,'real Ed25519 signature');
foreach(['source_sha256','timeout_seconds','workspace_id','script_id','version_id','provider','target_ids','parameters'] as $field){$bad=$m;$bad[$field]='changed';$reject(fn()=>ScriptVersion::verify(ScriptVersion::bytes($bad),$input['source'],$pub,$sig));}
$reject(fn()=>ScriptVersion::verify($bytes,'modified',$pub,$sig));$reject(fn()=>ScriptVersion::verify($bytes,$input['source'],str_repeat('0',64),$sig));
$ok(ScriptVersion::parameters($m,['limit'=>5,'enabled'=>true,'mode'=>'a'])===['enabled'=>true,'limit'=>5,'mode'=>'a'],'typed values normalized');
foreach([['limit'=>11,'enabled'=>true],['limit'=>'5','enabled'=>true],['limit'=>5],['limit'=>5,'enabled'=>true,'extra'=>1],['limit'=>5,'enabled'=>true,'mode'=>'c']] as $bad)$reject(fn()=>ScriptVersion::parameters($m,$bad));
foreach([['provider'=>'ssh'],['language'=>'powershell'],['source'=>"bad\0"],['timeout_seconds'=>91],['output_limit_bytes'=>300000],['target_ids'=>[$t]],['parameters'=>[['name'=>'x','type'=>'invalid']]]] as $patch)$reject(fn()=>ScriptVersion::manifest($w,$s,$v,array_replace($input,$patch)));
$templates=new ScriptTemplate();$ok(count($templates->all())===5,'five templates');foreach($templates->all() as $tpl){$m2=ScriptVersion::manifest($w,$s,$v,$tpl+['target_ids'=>[$t]]);$ok($m2['source_sha256']===hash('sha256',$tpl['source']),'template materialized '.$tpl['id']);}
$bad=$templates->get('snmp');$bad['source']='{"collector":"snmp.inventory","operation":"sync"}';$reject(fn()=>ScriptVersion::manifest($w,$s,$v,$bad+['target_ids'=>[$t]]));
$dir=sys_get_temp_dir().'/cajeer-sign-'.bin2hex(random_bytes(8));mkdir($dir,0700);
try {
    $cli=dirname(__DIR__).'/bin/script-sign.php';$file=$dir.'/key';$inputFile=$dir.'/bytes';file_put_contents($inputFile,$bytes);
    $run=function(array $args)use($cli){$pipes=[];$proc=proc_open([PHP_BINARY,$cli,...$args],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($proc),trim($out)];};
    [$status,$pub2]=$run(['generate',$file]);$ok($status===0&&strlen($pub2)===64&&(fileperms($file)&0777)===0600,'CLI generates private key with 0600');
    [$status,$sig2]=$run(['sign',$file,$inputFile]);$ok($status===0&&ScriptVersion::verify($bytes,$input['source'],$pub2,$sig2)===$m,'CLI produces actual signature');
    $ok($run(['generate',$file])[0]!==0,'existing key never overwritten');chmod($file,0644);$ok($run(['sign',$file,$inputFile])[0]!==0,'unsafe key permissions denied');
} finally {foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
$sql=file_get_contents(dirname(__DIR__).'/migrations/pgsql/0030_script_registry.sql');$statements=(new \Cajeer\Servers\Database\SqlStatementParser())->split($sql);
$ok(count($statements)===9,'migration parser preserves trigger function');
$app=(new Application(dirname(__DIR__)))->boot();try{$ok($app->container()->get(\Cajeer\Servers\Script\ScriptRegistry::class) instanceof \Cajeer\Servers\Script\ScriptRegistry,'DI registry');$routes=$app->container()->get(\Cajeer\Servers\Http\Router::class)->routes();$ok(count(array_filter($routes,fn($r)=>str_starts_with($r['path'],'/api/v1/workspaces/{workspace}/scripts')))===16,'16 routes registered');}finally{$app->terminate();}
echo "Script Registry: $n checks passed.\n";
