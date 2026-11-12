<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Http\Exception\HttpException;

/** Bounded, strict interchange format: no arbitrary attributes, secrets or executable payloads. */
final class TransferFormat
{
    public const MAX_BYTES = 524288;
    public const MAX_ROWS = 200;
    public const FIELDS = ['name','status','os_family','environment','ip_addresses','tags'];
    private const CSV = ['external_id','type','name','status','os_family','environment','ip_addresses','tags'];

    /** @return list<array{external_id:string,type:string,fields:array<string,mixed>}> */
    public static function parse(string $format,string $content,string $workspace):array
    {
        self::validateInput($format,$content);
        if (!InfrastructureAsset::isUuid($workspace)) self::bad('workspace_id');
        $rows = $format==='json' ? self::fromJson($content) : self::fromCsv($content);
        if ($rows===[] || count($rows)>self::MAX_ROWS) self::bad('rows');
        $out=[];$seen=[];
        foreach($rows as $index=>$row){
            if(!is_array($row)||array_is_list($row))self::bad('row '.($index+1));
            foreach($row as $key=>$_)if(!in_array($key,['external_id','type','fields'],true))self::bad('row '.($index+1));
            if(!isset($row['fields'])||!is_array($row['fields']))self::bad('fields');
            $observation=ObservationInput::normalize([
                'workspace_id'=>$workspace,'source'=>'import','external_id'=>$row['external_id']??null,
                'type'=>$row['type']??null,'event_key'=>'preview.'.($index+1),'fields'=>$row['fields'],
            ]);
            if(ObservationInput::reserved($observation['external_id']))self::bad('reserved connector external_id');
            // One external identity per batch: avoid contradictory replays within a single file.
            if(isset($seen[$observation['external_id']]))self::bad('duplicate external_id');
            $seen[$observation['external_id']]=true;
            // Store only normalized, allowlisted fields. Never persist untrusted extra keys.
            $out[]=['external_id'=>$observation['external_id'],'type'=>$observation['type'],'fields'=>$observation['fields']];
        }
        return $out;
    }

    public static function validateInput(string $format,string $content):void
    {
        if(!in_array($format,['csv','json'],true))self::bad('format');
        if($content===''||strlen($content)>self::MAX_BYTES||!preg_match('//u',$content))self::bad('content');
        if(str_contains($content,"\0"))self::bad('content');
    }

    /** @return list<array<string,mixed>> */
    private static function fromJson(string $content):array
    {
        try{$json=json_decode($content,true,12,JSON_THROW_ON_ERROR);}catch(\JsonException){self::bad('JSON');}
        if(!is_array($json)||!array_is_list($json))self::bad('JSON array');
        return $json;
    }

    /** @return list<array<string,mixed>> */
    private static function fromCsv(string $content):array
    {
        $file=fopen('php://temp/maxmemory:1048576','w+');
        if($file===false)throw new \RuntimeException('Unable to parse CSV.');
        try{
            fwrite($file,$content);rewind($file);
            $headers=fgetcsv($file,0,',','"','');
            if(!is_array($headers))self::bad('CSV header');
            $headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]);
            if($headers!==self::CSV)self::bad('CSV header');
            $rows=[];
            while(($columns=fgetcsv($file,0,',','"',''))!==false){
                if($columns===[null])continue;
                if(count($rows)>=self::MAX_ROWS)self::bad('rows');
                if(count($columns)!==count(self::CSV))self::bad('CSV columns');
                $data=array_combine(self::CSV,$columns);
                $fields=[];
                foreach(self::FIELDS as $key){
                    $raw=$data[$key];
                    if($raw==='')continue;
                    if(in_array($key,['tags','ip_addresses'],true)){
                        try{$decoded=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){self::bad($key);}
                        if(!is_array($decoded))self::bad($key);
                        $fields[$key]=$decoded;
                    }else{$fields[$key]=$raw;}
                }
                $rows[]=['external_id'=>$data['external_id'],'type'=>$data['type'],'fields'=>$fields];
            }
            return $rows;
        }finally{fclose($file);}
    }

    /** Avoid spreadsheet formula execution in exported CSV. */
    public static function cell(string $value):string
    {
        if(preg_match('/^[\x00-\x20]*[=+\-@]/u',$value) || preg_match('/^[\t\r\n]/',$value)) return "'".$value;
        return $value;
    }

    /** @param list<array<string,mixed>> $rows */
    public static function export(string $format,array $rows):string
    {
        if(!in_array($format,['csv','json'],true))self::bad('format');
        $items=[];
        foreach($rows as $row){
            // Export deliberately excludes raw attributes, secrets, credential references and owner emails.
            $items[]=['external_id'=>(string)($row['external_id']??$row['id']),
                'type'=>(string)$row['type'],'fields'=>[
                    'name'=>$row['name'],'status'=>$row['status'],'os_family'=>$row['os_family'],
                    'environment'=>$row['environment'],'ip_addresses'=>$row['ip_addresses'],'tags'=>$row['tags'],
                ]];
        }
        if($format==='json')return json_encode($items,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
        $stream=fopen('php://temp','w+');if($stream===false)throw new \RuntimeException('Unable to export CSV.');
        try{
            fputcsv($stream,self::CSV,',','"','');
            foreach($items as $item){
                $row=[$item['external_id'],$item['type']];
                foreach(self::FIELDS as $field){
                    $value=$item['fields'][$field];
                    $row[]=is_array($value)?json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE):($value??'');
                }
                fputcsv($stream,array_map(static fn($v)=>self::cell((string)$v),$row),',','"','');
            }
            rewind($stream);return (string)stream_get_contents($stream);
        }finally{fclose($stream);}
    }

    private static function bad(string $field):never
    {
        throw new HttpException(422,'Invalid inventory transfer field: '.$field,'validation_failed');
    }
}
