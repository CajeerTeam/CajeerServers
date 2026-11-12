<?php
declare(strict_types=1);
namespace Cajeer\Servers\Script;

use Cajeer\Servers\Http\Exception\HttpException;

/** A signature is not a sandbox. Remote arbitrary source is never passed to a transport. */
final class ScriptExecutionPolicy
{
    public static function channel(array $manifest, string $source): string
    {
        if ($manifest['provider']==='local' && in_array($manifest['language'], ['bash','python','powershell'], true)) return 'sandbox';
        $template=match($manifest['provider']) {
            'ssh'=>'linux', 'winrm'=>'windows', 'snmp'=>'snmp', 'proxmox'=>'proxmox', 'vmmanager'=>'vmmanager', default=>null,
        };
        if ($template!==null) {
            $expected=(new ScriptTemplate())->get($template);
            if ($manifest['language']===$expected['language'] && $manifest['parameters']===[] && hash_equals($expected['source'],$source)) return 'connector';
        }
        throw new HttpException(422,'Only the exact built-in remote collectors may execute. Use local sandbox for custom code.','script_policy_denied');
    }
}
