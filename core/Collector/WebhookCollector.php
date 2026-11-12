<?php
declare(strict_types=1);
namespace Cajeer\Servers\Collector;

use Cajeer\Servers\Connector\ConnectorTransportException;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Metrics\MetricBatch;
use Cajeer\Servers\Script\{DataNormalizer,ScriptOutputParser};

/** Push adapter. No network access or authority to modify Inventory. */
final class WebhookCollector
{
    public const KINDS=['metrics','events','observations'];
    public const MAX_BYTES=262144;
    public function name():string{return 'webhook.ingestion';}
    private static function invalid():never{throw new HttpException(422,'Invalid ingestion batch.','ingestion_invalid');}
    public function parse(string $body,string $kind,string $source):array
    {return $this->normalize($this->envelope($body,$kind),$source);}
    /** Shape and digest validation without time-dependent sample normalization (safe replay lookup). */
    public function envelope(string $body,string $kind):array
    {
        if(strlen($body)>self::MAX_BYTES)throw new HttpException(413,'Batch exceeds 256 KiB.','payload_too_large');
        try{$value=(new ScriptOutputParser())->json($body);}catch(HttpException){self::invalid();}
        if(!$value instanceof \stdClass)self::invalid();$p=get_object_vars($value);
        if(array_diff(array_keys($p),['schema_version','batch_id','kind','records'])||($p['schema_version']??null)!==1||($p['kind']??null)!==$kind||!in_array($kind,self::KINDS,true)||!is_string($p['batch_id']??null)||!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$p['batch_id'])||!is_array($p['records']??null)||!array_is_list($p['records'])||count($p['records'])<1||count($p['records'])>1000)self::invalid();
        $records=$p['records'];
        // Explicit timestamps make replay across different batch IDs deterministic.
        foreach($records as $r)if(!$r instanceof \stdClass||!is_string($r->observed_at??null))self::invalid();
        return ['batch_id'=>$p['batch_id'],'digest'=>hash('sha256',$body),'kind'=>$kind,'records'=>$records];
    }
    public function normalize(array $batch,string $source):array
    {
        $kind=$batch['kind'];$records=$batch['records'];
        if($kind==='metrics'){
            $rows=[];foreach($records as $r){$r=get_object_vars($r);$labels=$r['labels']??new \stdClass();if(!$labels instanceof \stdClass||property_exists($labels,'cajeer_source')||count(get_object_vars($labels))>15)self::invalid();
                // Source identity is server assigned, never a caller-supplied label.
                $labels->cajeer_source=$source;$r['labels']=$labels;
                // Large counters must be decimal strings; JSON floating values cannot preserve integer precision.
                if(is_float($r['value']??null)&&abs($r['value'])>9007199254740991)self::invalid();$rows[]=$r;}
            try{$out=MetricBatch::normalize($rows);}catch(ConnectorTransportException|\JsonException){self::invalid();}
        }else{
            try{$normalized=(new DataNormalizer())->normalize($records,'json',$kind);$out=$normalized[$kind];}catch(HttpException){self::invalid();}
        }
        if(strlen(json_encode($out,JSON_THROW_ON_ERROR))>524288)self::invalid();
        return ['batch_id'=>$batch['batch_id'],'digest'=>$batch['digest'],'records'=>$out];
    }
}
