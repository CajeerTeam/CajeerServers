<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Inventory\ObservationInput;
use RuntimeException;

/** Normalized discovery output. Validate even trusted adapters before persistence. */
final readonly class ConnectorResult
{
    public function __construct(public array $items, public bool $complete = false) {}
    public function validated(ConnectorManifest $manifest): self
    {
        if (!array_is_list($this->items) || count($this->items)>2000) throw new RuntimeException('Invalid discovery size.');
        $seen=[]; $items=[];
        foreach ($this->items as $item) {
            if (!is_array($item) || array_diff(array_keys($item),['type','external_id','fields','parent_external_id'])!==[] || !isset($item['type'],$item['external_id'],$item['fields'])
                || !is_string($item['external_id']) || strlen($item['external_id'])>180
                || !in_array($item['type'],$manifest->resourceTypes,true) || isset($seen[$item['external_id']]))
                throw new RuntimeException('Invalid discovery observation.');
            $seen[$item['external_id']]=true;
            $normalized=ObservationInput::normalize(['workspace_id'=>'11111111-1111-4111-8111-111111111111',
                'external_id'=>$item['external_id'],'event_key'=>'validation','type'=>$item['type'],'fields'=>$item['fields']],in_array($manifest->provider,['ssh','winrm'],true));
            $clean=['type'=>$normalized['type'],'external_id'=>$normalized['external_id'],'fields'=>$normalized['fields']];
            if (array_key_exists('parent_external_id',$item)) {
                $parent=$item['parent_external_id'];
                if (!is_string($parent) || $parent==='' || strlen($parent)>180 || preg_match('/[\x00-\x1f\x7f]/',$parent) || $parent===$item['external_id']) throw new RuntimeException('Invalid parent identity.');
                $clean['parent_external_id']=$parent;
            }
            $items[]=$clean;
        }
        // Parent identities describe discovery topology, not editable Inventory relationships.
        $parents=[]; foreach ($items as $item) if (isset($item['parent_external_id'])) $parents[$item['external_id']]=$item['parent_external_id'];
        foreach ($parents as $child=>$parent) {
            $visited=[$child=>true];
            while (true) {
                if (isset($visited[$parent])) throw new RuntimeException('Cyclic discovery topology.');
                if (!isset($seen[$parent]) && $this->complete) throw new RuntimeException('Full snapshot missing parent.');
                $visited[$parent]=true; if (!isset($parents[$parent])) break; $parent=$parents[$parent];
            }
        }
        return new self($items, $this->complete && $items !== []);
    }
    public function summary(ConnectorManifest $manifest): array
    {
        $counts=array_fill_keys($manifest->resourceTypes,0);
        foreach ($this->items as $item) $counts[$item['type']]++;
        return $counts;
    }
}
