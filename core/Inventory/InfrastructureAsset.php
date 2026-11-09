<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Http\Exception\HttpException;

/** Validated, manually managed inventory data. No credentials or executable commands accepted. */
final class InfrastructureAsset
{
    public const TYPES = ['server','hypervisor_node','cluster','virtual_machine','container','router','workstation','other'];
    public const STATUSES = ['unknown','online','offline','maintenance','provisioning','decommissioned'];
    public const OS_FAMILIES = ['windows','debian','ubuntu','linux','other'];
    public const FIELDS = ['name','status','os_family','ip_addresses','tags','attributes','owner_user_id'];

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function create(array $input): array
    {
        if (!isset($input['type']) || !is_string($input['type']) || !in_array($input['type'], self::TYPES, true)) self::invalid('type');
        if (!array_key_exists('name', $input)) self::invalid('name');
        return ['type'=>$input['type'],'source'=>'manual','external_id'=>null, ...self::changes(array_diff_key($input, ['type'=>true,'workspace_id'=>true]), true)];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function changes(array $input, bool $creating = false): array
    {
        $out = [];
        foreach ($input as $key=>$value) {
            if (!is_string($key) || !in_array($key,self::FIELDS,true)) self::invalid((string)$key);
            switch ($key) {
                case 'name':
                    if (!is_string($value)) self::invalid($key);
                    $value=trim($value);
                    if ($value==='' || strlen($value)>255 || preg_match('/[\x00-\x1F\x7F]/',$value)) self::invalid($key);
                    break;
                case 'status':
                    if (!is_string($value) || !in_array($value,self::STATUSES,true)) self::invalid($key);
                    break;
                case 'os_family':
                    if ($value !== null && (!is_string($value) || !in_array($value,self::OS_FAMILIES,true))) self::invalid($key);
                    break;
                case 'owner_user_id':
                    if ($value!==null && (!is_string($value) || !self::isUuid($value))) self::invalid($key);
                    break;
                case 'ip_addresses':
                    if (!is_array($value) || !array_is_list($value) || count($value)>64) self::invalid($key);
                    $ips=[];
                    foreach ($value as $ip) {
                        if (!is_string($ip) || strlen($ip)>45 || filter_var($ip,FILTER_VALIDATE_IP)===false) self::invalid($key);
                        $normalized = inet_ntop(inet_pton($ip));
                        if (isset($ips[$normalized])) self::invalid($key);
                        $ips[$normalized]=true;
                    }
                    $value=array_keys($ips);
                    break;
                case 'tags':
                    if (!is_array($value) || !array_is_list($value) || count($value)>50) self::invalid($key);
                    $tags=[];
                    foreach ($value as $tag) {
                        if (!is_string($tag) || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$tag)!==1 || isset($tags[$tag])) self::invalid($key);
                        $tags[$tag]=true;
                    }
                    $value=array_keys($tags);
                    break;
                case 'attributes':
                    if (!is_array($value) || (array_is_list($value) && $value!==[])) self::invalid($key);
                    try { $json=json_encode($value,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE); }
                    catch (\JsonException) { self::invalid($key); }
                    if (strlen($json)>16384 || count($value)>100 || !self::safeMetadata($value)) self::invalid($key);
                    break;
            }
            $out[$key]=$value;
        }
        if (!$creating && !$out) self::invalid('patch');
        if ($creating) $out=['name'=>$out['name'], 'status'=>$out['status']??'unknown', 'os_family'=>$out['os_family']??null,
            'owner_user_id'=>$out['owner_user_id']??null,'ip_addresses'=>$out['ip_addresses']??[], 'tags'=>$out['tags']??[], 'attributes'=>$out['attributes']??[]];
        return $out;
    }

    private static function safeMetadata(array $value, int $depth=0): bool
    {
        if ($depth>4) return false;
        foreach ($value as $key=>$item) {
            if (is_string($key) && (strlen($key)>80 || preg_match('/[\x00-\x1F\x7F]/',$key) || preg_match('/password|secret|token|credential|private.?key|api.?key/i',$key))) return false;
            if (is_array($item)) {
                if (count($item)>100 || !self::safeMetadata($item,$depth+1)) return false;
            } elseif (!is_string($item) && !is_int($item) && !is_float($item) && !is_bool($item) && $item!==null) return false;
            if (is_string($item) && strlen($item)>4096) return false;
        }
        return true;
    }

    public static function isUuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$value)===1;
    }

    public static function etag(string $id, int $version): string
    {
        return '"'.strtolower($id).':'.$version.'"';
    }

    private static function invalid(string $field): never
    {
        throw new HttpException(422,'Invalid inventory field: '.$field,'validation_failed', errors:[['field'=>$field,'message'=>'Invalid value.']]);
    }
}
