<?php
declare(strict_types=1);
namespace Cajeer\Servers\Script;
use Cajeer\Servers\Http\Exception\HttpException;

/** Templates use real fixed host collectors or an existing named connector plan. No source interpolation. */
final class ScriptTemplate
{
    public function all(): array
    {
        $base=dirname(__DIR__).'/Connector/agents/';
        $templates=[];
        foreach(['linux'=>['python','ssh','linux_collect.py'],'windows'=>['powershell','winrm','windows_collect.ps1']] as $id=>[$lang,$provider,$file]) {
            $source=file_get_contents($base.$file);if($source===false)throw new \RuntimeException('Template source unavailable.');
            $templates[]=['id'=>$id,'language'=>$lang,'provider'=>$provider,'source'=>$source,'parameters'=>[],'timeout_seconds'=>90,'output_limit_bytes'=>262144];
        }
        foreach(['snmp','proxmox','vmmanager'] as $provider)$templates[]=['id'=>$provider,'language'=>'connector','provider'=>$provider,'source'=>json_encode(['collector'=>$provider.'.inventory','operation'=>'inspect'],JSON_THROW_ON_ERROR),'parameters'=>[],'timeout_seconds'=>90,'output_limit_bytes'=>262144];
        return $templates;
    }
    public function get(string $id): array {foreach($this->all() as $t)if($t['id']===$id){unset($t['id']);return $t;}throw new HttpException(404,'Template not found.','not_found');}
}
