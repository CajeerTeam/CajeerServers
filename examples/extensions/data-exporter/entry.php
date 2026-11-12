<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/packages/php-sdk/src/Exporter.php';
use Cajeer\Servers\ImportExport\ExporterInterface;
use Cajeer\ServersSdk\Exporter;
// Installed by the operator via DATA_EXPORTER_EXTENSION_FILE. NDJSON streaming serialization.
return static function(int $sdk):ExporterInterface {
    if($sdk!==1)throw new RuntimeException('Unsupported Data SDK.');
    return new class implements Exporter {
        public function name():string{return 'ndjson';}
        public function supports(string $kind):bool{return in_array($kind,['metrics','events'],true);}
        public function encode(string $kind,iterable $records):string{
            $out='';foreach($records as $row){$out.=json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";if(strlen($out)>4194304)throw new \Cajeer\Servers\Http\Exception\HttpException(422,'Export exceeds limit.','export_limit');}return $out;
        }
    };
};
