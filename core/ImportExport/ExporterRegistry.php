<?php
declare(strict_types=1);
namespace Cajeer\Servers\ImportExport;
use Cajeer\Servers\Http\Exception\HttpException;
final class ExporterRegistry
{
    private array $items=[];
    public function __construct(string $file='')
    {
        foreach(['json','csv','openmetrics'] as $f)$this->register(new BuiltinExporter($f));
        if($file!==''){
            if(!str_starts_with($file,'/')||!is_file($file)||!is_readable($file))throw new \RuntimeException('Invalid exporter extension file.');
            $factory=require $file;if(!$factory instanceof \Closure)throw new \RuntimeException('Expected exporter factory.');
            $exporter=$factory(1);if(!$exporter instanceof ExporterInterface)throw new \RuntimeException('Invalid exporter extension.');$this->register($exporter);
        }
    }
    public function register(ExporterInterface $exporter):void
    {
        $name=$exporter->name();if(!preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$name)||isset($this->items[$name]))throw new \LogicException('Invalid or duplicate exporter.');$this->items[$name]=$exporter;
    }
    public function require(string $format,string $kind):ExporterInterface
    {
        $e=$this->items[$format]??null;if(!$e||$e->name()!==$format||!$e->supports($kind))throw new HttpException(422,'Exporter unavailable for this kind.','validation_failed');return $e;
    }
    public function formats():array{return array_map(fn($f)=>['format'=>$f,'kinds'=>array_values(array_filter(['metrics','events','observations','runs'],fn($k)=>$this->items[$f]->supports($k)))],array_keys($this->items));}
}
