<?php
declare(strict_types=1);
namespace Cajeer\Servers\ImportExport;

use Cajeer\Servers\Collector\WebhookCollector;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Script\ScriptOutputParser;

/** Convert a bounded manual file into the exact same envelope as signed ingestion. */
final class ImportManager
{
    public static function envelope(string $format,string $kind,string $batchId,string $content):string
    {
        if(strlen($content)>WebhookCollector::MAX_BYTES)throw new HttpException(413,'Import exceeds 256 KiB.','payload_too_large');
        $parser=new ScriptOutputParser();
        if($format==='json'){
            $rows=$parser->json($content);
            // Accept the signed script runner's normalized schema envelope as data, never authority.
            if($rows instanceof \stdClass){
                $field=$kind==='metrics'?'metric_samples':$kind;
                $obj=get_object_vars($rows);
                // Own JSON exports are portable data, not authority. Explicitly project only
                // portable fields and discard old provenance; raw inputs still use strict validation.
                if(array_keys($obj)===['schema_version','kind','records']||(!array_diff(array_keys($obj),['schema_version','kind','records'])&&count($obj)===3)){
                    if($obj['schema_version']!==1||$obj['kind']!==$kind||!is_array($obj['records'])||!array_is_list($obj['records']))throw new HttpException(422,'Export kind does not match import source.','validation_failed');
                    $rows=self::portable($kind,$obj['records']);
                }else{
                if(array_diff(array_keys($obj),['schema_version','schema','complete','count',$field])||($obj['schema_version']??null)!==1||($obj['schema']??null)!==$kind||!is_bool($obj['complete']??null)||!is_array($obj[$field]??null)||($obj['count']??null)!==count($obj[$field]))throw new HttpException(422,'Expected a normalized result matching the source kind.','validation_failed');
                $rows=$obj[$field];
                }
            }
        }
        elseif($format==='csv')$rows=$parser->parse($content,['parser'=>'csv','output_schema'=>'document'])['output'];
        else throw new HttpException(422,'Unsupported import format.','validation_failed');
        if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>1000)throw new HttpException(422,'Expected 1–1000 records.','validation_failed');
        if($format==='csv')foreach($rows as $row){
            if($kind==='metrics'){
                if(isset($row->labels))$row->labels=$row->labels===''?new \stdClass():$parser->json($row->labels);
                if(($row->unit??null)==='')unset($row->unit);
                if(($row->kind??null)==='')unset($row->kind);
            }elseif($kind==='observations'){
                foreach(['tags','ip_addresses'] as $key)if(isset($row->$key))$row->$key=$row->$key===''?[]:explode(';',$row->$key);
                if(isset($row->fields))$row->fields=$parser->json($row->fields);
            }
        }
        $body=json_encode(['schema_version'=>1,'batch_id'=>$batchId,'kind'=>$kind,'records'=>$rows],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        // Envelope validation also enforces explicit timestamps and canonical batch IDs.
        (new WebhookCollector())->envelope($body,$kind);
        return $body;
    }
    private static function portable(string $kind,array $rows):array
    {
        $fields=match($kind){
            'metrics'=>['metric','value','kind','unit','resource_external_id','labels','observed_at'],
            'events'=>['event_id','type','severity','message','observed_at'],
            'observations'=>['external_id','type','fields','observed_at'],
            default=>throw new HttpException(422,'This export kind cannot be imported.','validation_failed'),
        };
        $provenance=match($kind){'metrics'=>['series_id','asset_id','target_version','ingestion_source_id'],'events'=>['source_id'],'observations'=>['batch_id','source_id','received_at']};
        $out=[];$identities=[];
        foreach($rows as $row){
            if(!$row instanceof \stdClass||array_diff(array_keys(get_object_vars($row)),[...$fields,...$provenance]))throw new HttpException(422,'Invalid exported record.','validation_failed');
            $record=(object)array_intersect_key(get_object_vars($row),array_flip($fields));
            if($kind==='metrics'){
                if(isset($record->labels)&&$record->labels instanceof \stdClass){
                    $record->labels=clone $record->labels;
                    if(property_exists($record->labels,'cajeer_source')){
                        if(($row->ingestion_source_id??null)!==$record->labels->cajeer_source)throw new HttpException(422,'Unexpected reserved source label.','validation_failed');
                        unset($record->labels->cajeer_source);
                    }
                }
                // Reject collapsing two exported series into one new source identity.
                $labels=(array)($record->labels??[]);ksort($labels,SORT_STRING);
                $identity=json_encode([$record->metric??null,$record->resource_external_id??null,$labels],JSON_THROW_ON_ERROR);
                $origin=$row->series_id??null;
                if(array_key_exists($identity,$identities)&&$identities[$identity]!==$origin)throw new HttpException(422,'Import separate exported series into separate sources.','validation_failed');
                $identities[$identity]=$origin;
            }
            $out[]=$record;
        }
        return $out;
    }
    public static function preview(string $body,string $kind,string $source):array
    {
        $collector=new WebhookCollector();$batch=$collector->envelope($body,$kind);
        try{return $collector->normalize($batch,$source);}catch(HttpException $e){
            $errors=[];foreach($batch['records'] as $i=>$row){
                try{$collector->normalize([...$batch,'records'=>[$row]],$source);}catch(HttpException){$errors[]=['field'=>'records.'.($i+1),'message'=>'Invalid record.'];if(count($errors)>=20)break;}
            }
            if(!$errors)$errors[]=['field'=>'records','message'=>'Conflicting or duplicate records, or batch limit exceeded.'];
            throw new HttpException(422,'Import validation failed.','ingestion_invalid',[],$errors);
        }
    }

}
