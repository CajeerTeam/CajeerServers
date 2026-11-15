<?php
declare(strict_types=1);
// Private CLI-only protocol helper. Exactly one credential/session per process.
if (PHP_SAPI !== 'cli') exit(2);
require dirname(__DIR__,2).'/autoload.php';
use Cajeer\Servers\Connector\{NativeSnmpSession,ConnectorTransportException};

$session=null;
$send=static function(array $reply):void {
    $line=json_encode($reply,JSON_THROW_ON_ERROR)."\n";
    if(strlen($line)>262144)throw new ConnectorTransportException('connector_protocol_error');
    $offset=0;
    while($offset<strlen($line)){
        $n=fwrite(STDOUT,substr($line,$offset));
        if($n===false||$n===0)exit(1);
        $offset+=$n;
    }
    fflush(STDOUT);
};
$pair=static fn(array $v):array=>$v===[null,null]?$v:[$v[0],base64_encode($v[1])];
$oid=static function(mixed $v):string {
    if(!is_string($v)||!preg_match('/^\.(?:0|[1-9][0-9]{0,9})(?:\.(?:0|[1-9][0-9]{0,9})){1,127}$/D',$v))throw new ConnectorTransportException('connector_protocol_error');
    return $v;
};
try {
    while(($line=fgets(STDIN,262145))!==false){
        if(strlen($line)>262144||!str_ends_with($line,"\n"))throw new ConnectorTransportException('connector_protocol_error');
        $request=json_decode($line,true,16,JSON_THROW_ON_ERROR);sodium_memzero($line);
        if(!is_array($request))throw new ConnectorTransportException('connector_protocol_error');
        $op=$request['op']??null;
        if($session===null){
            if($op!=='open'||!is_string($request['ip']??null)||filter_var($request['ip'],FILTER_VALIDATE_IP)===false||!is_int($request['port']??null)||$request['port']<1||$request['port']>65535||!in_array($request['type']??null,['snmp_v2c','snmp_v3'],true)||!is_array($request['credential']??null))throw new ConnectorTransportException('connector_protocol_error');
            try{$session=new NativeSnmpSession($request['ip'],$request['port'],$request['type'],$request['credential']);}
            finally{foreach($request['credential'] as &$value)if(is_string($value))sodium_memzero($value);unset($value,$request['credential']);}
            $send(['data'=>true]);continue;
        }
        if($op==='get')$data=base64_encode($session->get($oid($request['oid']??null)));
        elseif($op==='next')$data=$pair($session->next($oid($request['oid']??null)));
        elseif($op==='batch'){
            $oids=$request['oids']??null;
            if(!is_array($oids)||!array_is_list($oids)||count($oids)<2||count($oids)>16)throw new ConnectorTransportException('connector_protocol_error');
            $data=$session->nextBatch(array_map($oid,$oids));if($data!==null)$data=array_map($pair,$data);
        }else throw new ConnectorTransportException('connector_protocol_error');
        $send(['data'=>$data]);
    }
}catch(ConnectorTransportException $e){$send(['error'=>$e->category]);}
catch(Throwable){$send(['error'=>'connector_protocol_error']);}
finally{$session?->close();}
