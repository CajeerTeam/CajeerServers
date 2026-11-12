<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Http\Exception\HttpException;

/** Non-secret, versioned trust policy. Pins are SHA-256 of the TLS leaf certificate (DER). */
final class ProxmoxProfile
{
    private static function invalid(): never { throw new HttpException(422,'Invalid Proxmox connection profile.','validation_failed'); }
    public static function normalize(mixed $input,string $host,int $port): array
    {
        if (!is_array($input)||array_diff(array_keys($input),['cluster','anchor_node','anchor_id','primary_pin','fallbacks'])!==[]) self::invalid();
        $cluster=$input['cluster']??null; $node=$input['anchor_node']??null; $id=$input['anchor_id']??null;
        $name=static fn($s):bool=>is_string($s)&&preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D',$s)===1;
        if (($cluster!==null&&!$name($cluster))||!$name($node)||($cluster!==null&&(!is_int($id)||$id<1||$id>2147483647))||($cluster===null&&$id!==null)) self::invalid();
        $pin=self::pin($input['primary_pin']??null); $fallbacks=$input['fallbacks']??[];
        if (!is_array($fallbacks)||!array_is_list($fallbacks)||count($fallbacks)>2||($cluster===null&&$fallbacks!==[])) self::invalid();
        $seen=[json_encode([$host,$port])=>true];$out=[];
        foreach ($fallbacks as $e) {
            if (!is_array($e)||array_diff(array_keys($e),['host','port','pin'])!==[]) self::invalid();
            $h=ConnectorBindingService::validateHost($e['host']??null);$p=ConnectorBindingService::validatePort($e['port']??null);
            $key=json_encode([$h,$p]);if(isset($seen[$key]))self::invalid();$seen[$key]=true;
            $out[]=['host'=>$h,'port'=>$p,'pin'=>self::pin($e['pin']??null)];
        }
        return ['cluster'=>$cluster,'anchor_node'=>$node,'anchor_id'=>$id,'primary_pin'=>$pin,'fallbacks'=>$out];
    }
    private static function pin(mixed $pin): string
    { if(!is_string($pin)||!preg_match('/^[a-fA-F0-9]{64}$/D',$pin))self::invalid();return strtolower($pin); }
    public static function endpoints(ConnectorContext $context): array
    { $p=$context->proxmoxProfile;return [['host'=>$context->host,'port'=>$context->port,'pin'=>$p['primary_pin']??null],...($p['fallbacks']??[])]; }
    /** A name is not a cryptographic cluster ID: TLS pins are the trust anchor, membership is a second guard. */
    public static function verifyIdentity(array $profile,array $rows): array
    {
        $cluster=null;$nodes=[];$local=0;
        foreach($rows as $r){
            if(($r['type']??null)==='cluster') {if($cluster!==null)self::trust();$cluster=$r;}
            elseif(($r['type']??null)==='node') {
                $name=$r['name']??null;if(!is_string($name)||isset($nodes[$name]))self::trust();$nodes[$name]=$r;
                if(in_array($r['local']??null,[1,'1',true],true))$local++;
            }
        }
        if($local!==1||count($nodes)>256||!isset($nodes[$profile['anchor_node']]))self::trust();
        if($profile['cluster']===null){if($cluster!==null||count($nodes)!==1)self::trust();}
        else{
            if(($cluster['name']??null)!==$profile['cluster'])self::trust();
            $anchor=$nodes[$profile['anchor_node']]['nodeid']??null;
            if((!is_int($anchor)&&!is_string($anchor))||(string)$anchor!==(string)$profile['anchor_id'])self::trust();
            if(!in_array($cluster['quorate']??null,[0,1,'0','1',false,true],true))self::trust();
        }
        return ['cluster'=>$profile['cluster'],'anchor_node'=>$profile['anchor_node'],'anchor_id'=>$profile['anchor_id'],'visible_members'=>count($nodes),'quorate'=>$cluster===null?null:in_array($cluster['quorate']??null,[1,'1',true],true)];
    }
    private static function trust():never {throw new ConnectorTransportException('connector_trust_failed');}
}
