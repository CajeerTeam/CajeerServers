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
                if(array_diff(array_keys($obj),['schema_version','schema','complete','count',$field])||($obj['schema_version']??null)!==1||($obj['schema']??null)!==$kind||!is_bool($obj['complete']??null)||!is_array($obj[$field]??null)||($obj['count']??null)!==count($obj[$field]))throw new HttpException(422,'Expected a normalized result matching the source kind.','validation_failed');
                $rows=$obj[$field];
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
