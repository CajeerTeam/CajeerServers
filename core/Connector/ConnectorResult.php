<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Inventory\ObservationInput;
use RuntimeException;

/** Normalized discovery output. Validate even trusted adapters before persistence. */
final readonly class ConnectorResult
{
    public function __construct(public array $items) {}
    public function validated(ConnectorManifest $manifest): self
    {
        if (!array_is_list($this->items) || count($this->items)>2000) throw new RuntimeException('Invalid discovery size.');
        $seen=[]; $items=[];
        foreach ($this->items as $item) {
            if (!is_array($item) || count($item)!==3 || !isset($item['type'],$item['external_id'],$item['fields'])
                || !is_string($item['external_id']) || strlen($item['external_id'])>180
                || !in_array($item['type'],$manifest->resourceTypes,true) || isset($seen[$item['external_id']]))
                throw new RuntimeException('Invalid discovery observation.');
            $seen[$item['external_id']]=true;
            $normalized=ObservationInput::normalize(['workspace_id'=>'11111111-1111-4111-8111-111111111111',
                'external_id'=>$item['external_id'],'event_key'=>'validation','type'=>$item['type'],'fields'=>$item['fields']]);
            $items[]=['type'=>$normalized['type'],'external_id'=>$normalized['external_id'],'fields'=>$normalized['fields']];
        }
        return new self($items);
    }
    public function summary(ConnectorManifest $manifest): array
    {
        $counts=array_fill_keys($manifest->resourceTypes,0);
        foreach ($this->items as $item) $counts[$item['type']]++;
        return $counts;
    }
}
