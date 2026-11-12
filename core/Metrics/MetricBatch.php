<?php
declare(strict_types=1);
namespace Cajeer\Servers\Metrics;

use Cajeer\Servers\Connector\ConnectorTransportException;

/** Bounded numeric data; decimal strings preserve Counter64 precision. */
final class MetricBatch
{
    public const MAX_SAMPLES=2000;
    public static function invalid(): never {throw new ConnectorTransportException('connector_protocol_error');}
    public static function sample(string $metric, mixed $value, string $resource='', array $labels=[], string $kind='gauge', ?string $unit=null, ?string $at=null): array
    {
        return ['metric'=>$metric,'value'=>$value,'resource_external_id'=>$resource,'labels'=>$labels,'kind'=>$kind,'unit'=>$unit,'observed_at'=>$at];
    }
    public static function normalize(array $rows, ?string $defaultAt=null): array
    {
        if (!array_is_list($rows)||count($rows)>self::MAX_SAMPLES) self::invalid();
        $now=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
        $out=[];$seen=[];
        foreach($rows as $r){
            if(!is_array($r)||array_diff(array_keys($r),['metric','value','resource_external_id','labels','kind','unit','observed_at']))self::invalid();
            $name=$r['metric']??null;$v=$r['value']??null;$resource=$r['resource_external_id']??'';$labels=$r['labels']??[];$kind=$r['kind']??'gauge';$unit=$r['unit']??null;
            if(!is_string($name)||!preg_match('/^[A-Za-z_:][A-Za-z0-9_.:-]{0,127}$/D',$name)||!is_string($resource)||strlen($resource)>180||!preg_match('/^[^\x00-\x1f\x7f]*$/uD',$resource)||!in_array($kind,['gauge','counter'],true)||($unit!==null&&(!is_string($unit)||!preg_match('/^[A-Za-z0-9_%\/.:-]{1,32}$/D',$unit))))self::invalid();
            if(is_int($v)||is_float($v))$v=json_encode($v,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
            if(!is_string($v)||strlen($v)>80||!preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]{1,3})?$/D',$v)||!is_finite((float)$v)||($kind==='counter'&&str_starts_with($v,'-')))self::invalid();
            if(is_object($labels))$labels=get_object_vars($labels);
            if(!is_array($labels)||count($labels)>16)self::invalid();
            foreach($labels as $k=>$x)if(!is_string($k)||!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D',$k)||str_starts_with($k,'__')||!is_string($x)||strlen($x)>256||!preg_match('/^[^\x00-\x08\x0b-\x1f\x7f]*$/uD',$x))self::invalid();
            ksort($labels,SORT_STRING);
            $at=$r['observed_at']??$defaultAt;
            if($at!==null){if(!is_string($at)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D',$at))self::invalid();
                try{$t=new \DateTimeImmutable($at);$errors=\DateTimeImmutable::getLastErrors();}catch(\Exception){self::invalid();}
                if($errors!==false||$t->getTimestamp()<$now->getTimestamp()-30*86400||$t->getTimestamp()>$now->getTimestamp()+300)self::invalid();
                $at=$t->format('Y-m-d\TH:i:s.u\Z');}
            $key=json_encode([$name,$resource,$labels,$at],JSON_THROW_ON_ERROR);if(isset($seen[$key]))self::invalid();$seen[$key]=true;
            $out[]=self::sample($name,$v,$resource,$labels,$kind,$unit,$at);
        }
        if(strlen(json_encode($out,JSON_THROW_ON_ERROR))>1048576)self::invalid();return $out;
    }
}
