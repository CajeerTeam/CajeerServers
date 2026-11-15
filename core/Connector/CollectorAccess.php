<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Shared policy for collection execution, history and scheduler admission. */
final class CollectorAccess
{
    public static function readPermission(?string $collector): ?string
    {
        return match ($collector) {
            'routeros.configuration-backup' => 'network.configuration.read',
            'ssh.events','winrm.events' => 'system.events.read',
            'vmmanager.network','routeros.network','routeros.routing','snmp.interfaces','snmp.topology','ssh.network','winrm.network','proxmox.storage-network' => 'inventory.network.read',
            default => null,
        };
    }
    public static function executePermissions(?string $collector, string $operation): array
    {
        if ($collector==='routeros.configuration-backup') return ['network.configuration.backup','network.configuration.read'];
        $read=self::readPermission($collector);
        return $read===null?[]:($operation==='sync' && $read==='inventory.network.read'?[$read,'inventory.network.manage']:[$read]);
    }
    /** SQL predicate for paginated history. All values originate from this fixed policy. */
    public static function historyFilter(array $permissions, string $column): string
    {
        if (!in_array($column,['p.collector_name','collector_name'],true)) throw new \InvalidArgumentException('Invalid history column.');
        $excluded=[];
        foreach (['routeros.configuration-backup','ssh.events','winrm.events','vmmanager.network','routeros.network','routeros.routing','snmp.interfaces','snmp.topology','ssh.network','winrm.network','proxmox.storage-network'] as $collector) {
            if (!in_array(self::readPermission($collector),$permissions,true)) $excluded[]=$column." IS DISTINCT FROM '".$collector."'";
        }
        return $excluded===[]?'':' AND '.implode(' AND ',$excluded);
    }
}
