<?php
declare(strict_types=1);
namespace Cajeer\Servers\Script;

use Cajeer\Servers\Inventory\ObservationInput;

/** Typed, versioned results. These are candidates, not permission to write Inventory or time series. */
final class DataNormalizer
{
    public const SCHEMAS=['document','observations','metrics','events'];
    private static function bad():never {ScriptOutputParser::invalid();}
    private static function map(mixed $value):array {if(!is_object($value))self::bad();return get_object_vars($value);}
    private static function only(array $r,array $allowed):void {if(array_diff(array_keys($r),$allowed))self::bad();}
    private static function text(mixed $v,int $limit,string $pattern='/^[^\x00-\x1f\x7f]+$/uD'):string {if(!is_string($v)||strlen($v)>$limit||!preg_match($pattern,$v))self::bad();return $v;}
    private static function time(mixed $value,\DateTimeImmutable $now):string
    {
        if($value===null)return $now->format('Y-m-d\TH:i:s.u\Z');
        self::text($value,40,'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D');
        if(preg_match('/[+-]([0-9]{2}):([0-9]{2})$/D',$value,$offset)&&((int)$offset[1]>23||(int)$offset[2]>59))self::bad();
        try{$t=new \DateTimeImmutable($value);$errors=\DateTimeImmutable::getLastErrors();}catch(\Exception){self::bad();}
        if($errors!==false&&($errors['warning_count']||$errors['error_count'])||$t->getTimestamp()>$now->getTimestamp()+300||$t->getTimestamp()<$now->getTimestamp()-366*86400)self::bad();
        return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
    /** Preserve exact decimal strings when a PHP/JS number cannot represent the input safely. */
    private static function number(mixed $v,bool $text):int|float|string
    {
        if(is_string($v)){
            if(strlen($v)>80||!preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]{1,3})?$/D',$v)||!is_finite((float)$v))self::bad();
            // Explicit JSON decimal strings are lossless. Text formats keep the legacy numeric
            // representation for short values, but never round high-precision decimals/counters.
            if(!$text)return $v;
            $mantissa=preg_split('/[eE]/',$v,2)[0];
            $digits=ltrim(str_replace(['+','-','.'],'',$mantissa),'0');
            if(strlen($digits)>15||abs((float)$v)>9007199254740991||((float)$v===0.0&&$digits!==''))return $v;
            $integer=filter_var($v,FILTER_VALIDATE_INT);return $integer===false?(float)$v:$integer;
        }
        if((!is_int($v)&&!is_float($v))||!is_finite((float)$v))self::bad();
        if(is_int($v)&&($v>9007199254740991||$v< -9007199254740991))return (string)$v;
        if(is_float($v)&&abs($v)>9007199254740991)self::bad();
        return $v;
    }
    public function normalize(mixed $value,string $format,string $schema):?array
    {
        if($schema==='document')return null;
        if(!in_array($schema,self::SCHEMAS,true))self::bad();
        $rows=is_array($value)?$value:[$value];if(!array_is_list($rows)||count($rows)>ScriptOutputParser::MAX_ROWS)self::bad();
        $now=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));$out=[];$seen=[];$series=[];
        foreach($rows as $row){$r=self::map($row);$text=$format!=='json';
            if($schema==='observations'){
                self::only($r,['external_id','type','fields','name','status','os_family','environment','ip_addresses','tags','observed_at']);
                $id=self::text($r['external_id']??null,180);if(ObservationInput::reserved($id))self::bad();
                if(isset($r['fields'])){if($text||array_intersect(array_keys($r),ObservationInput::FIELDS))self::bad();$fields=self::map($r['fields']);}
                else{$fields=array_intersect_key($r,array_flip(ObservationInput::FIELDS));if($text)foreach(['ip_addresses','tags'] as $k)if(isset($fields[$k]))$fields[$k]=$fields[$k]===''?[]:explode(';',$fields[$k]);}
                try{$obs=ObservationInput::normalize(['workspace_id'=>'11111111-1111-4111-8111-111111111111','external_id'=>$id,'event_key'=>'validation','type'=>$r['type']??null,'fields'=>$fields,'observed_at'=>self::time($r['observed_at']??null,$now)]);}catch(\Throwable){self::bad();}
                $item=['external_id'=>$id,'type'=>$obs['type'],'fields'=>$obs['fields'],'observed_at'=>$obs['observed_at']];$key=$id;
            }elseif($schema==='metrics'){
                self::only($r,['metric','value','unit','labels','resource_external_id','observed_at','kind']);
                $metric=self::text($r['metric']??null,128,'/^[A-Za-z_:][A-Za-z0-9_.:-]*$/D');$labels=[];
                if(isset($r['labels'])){if(is_string($r['labels'])&&$text&&$format!=='prometheus')$r['labels']=(new ScriptOutputParser())->json($r['labels']);$labels=self::map($r['labels']);}
                if(count($labels)>16)self::bad();foreach($labels as $k=>$v){self::text($k,64,'/^[A-Za-z_][A-Za-z0-9_]*$/D');if(str_starts_with($k,'__')||!is_string($v)||strlen($v)>256||preg_match('/[\x00-\x08\x0b-\x1f\x7f]/',$v))self::bad();}ksort($labels,SORT_STRING);
                $resource=isset($r['resource_external_id'])?self::text($r['resource_external_id'],180):null;if($resource!==null&&ObservationInput::reserved($resource))self::bad();
                $item=['metric'=>$metric,'value'=>self::number($r['value']??null,$text),'unit'=>isset($r['unit'])?self::text($r['unit'],32,'/^[A-Za-z0-9_%\/.:-]+$/D'):null,'resource_external_id'=>$resource,'labels'=>(object)$labels,'observed_at'=>self::time($r['observed_at']??null,$now)];
                if(isset($r['kind'])){
                    if(!in_array($r['kind'],['gauge','counter'],true)||($r['kind']==='counter'&&str_starts_with((string)$item['value'],'-')))self::bad();
                    $item['kind']=$r['kind'];
                }
                $seriesKey=json_encode([$metric,$resource,$labels],JSON_THROW_ON_ERROR);$series[$seriesKey]=true;if(count($series)>1000)self::bad();$key=$seriesKey.'|'.$item['observed_at'];
            }else{
                self::only($r,['event_id','type','message','severity','observed_at']);
                $id=self::text($r['event_id']??null,128,'/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D');$severity=$r['severity']??'info';if(!in_array($severity,['debug','info','warning','error','critical'],true))self::bad();
                $item=['event_id'=>$id,'type'=>self::text($r['type']??null,128,'/^[A-Za-z][A-Za-z0-9_.:-]*$/D'),'message'=>self::text($r['message']??null,4096,'/^[^\x00-\x08\x0b-\x1f\x7f]+$/uD'),'severity'=>$severity,'observed_at'=>self::time($r['observed_at']??null,$now)];$key=$id;
            }
            if(isset($seen[$key]))self::bad();$seen[$key]=true;$out[]=$item;
        }
        return ['schema_version'=>1,'schema'=>$schema,'complete'=>false,'count'=>count($out),match($schema){'metrics'=>'metric_samples',default=>$schema}=>$out];
    }
}
