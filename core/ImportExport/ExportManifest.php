<?php
declare(strict_types=1);
namespace Cajeer\Servers\ImportExport;
use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Http\Exception\HttpException;
final class ExportManifest
{
    public static function validate(array $input):array
    {
        if(array_diff(array_keys($input),['kind','format','from','to','series_id'])||!in_array($input['kind']??null,['metrics','events','observations','runs'],true)||!is_string($input['format']??null)||!preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$input['format']))self::bad();
        $times=[];foreach(['from','to'] as $key){$v=$input[$key]??null;if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D',$v))self::bad();try{$t=new \DateTimeImmutable($v);if(\DateTimeImmutable::getLastErrors()!==false)self::bad();}catch(\Exception){self::bad();}$times[$key]=$t;}
        if($times['to']<=$times['from']||$times['to']>$times['from']->modify('+30 days')||$times['to']->getTimestamp()>time()+300)self::bad();
        $series=isset($input['series_id'])?CredentialInput::uuid($input['series_id']):null;if($series!==null&&$input['kind']!=='metrics')self::bad();
        return ['kind'=>$input['kind'],'format'=>$input['format'],'from'=>$times['from']->format('Y-m-d\TH:i:s.u\Z'),'to'=>$times['to']->format('Y-m-d\TH:i:s.u\Z'),'series_id'=>$series];
    }
    private static function bad():never{throw new HttpException(422,'Invalid export selection (UTC, maximum 30 days).','validation_failed');}
}
