<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Native export validation and bounded, order-preserving line comparison. */
final class NetworkConfigurationBackup
{
    public const MAX_BYTES=1048576;
    public static function validate(#[\SensitiveParameter] string $text):string
    {
        if(trim($text)===''||strlen($text)>self::MAX_BYTES||substr_count($text,"\n")>20000||!preg_match('//u',$text)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text)||preg_match('/^\s*#\s*error exporting\b/im',$text)||!preg_match('/^# .*by RouterOS 7\./m',$text))RouterOsApiClient::invalid();
        return $text;
    }
    /** Ignore only the generated timestamp header; preserve order, comments and all other text. */
    public static function canonical(#[\SensitiveParameter] string $text):string
    {
        $text=str_replace(["\r\n","\r"],"\n",$text);
        return preg_replace('/^# [^\n]* by RouterOS (7\.[^\n]*)\n/', '# RouterOS $1' . "\n",$text,1)??$text;
    }
    public static function diff(#[\SensitiveParameter] string $old,#[\SensitiveParameter] string $new):array
    {
        $a=explode("\n",self::canonical($old));$b=explode("\n",self::canonical($new));
        $prefix=0;$n=count($a);$m=count($b);while($prefix<$n&&$prefix<$m&&$a[$prefix]===$b[$prefix])$prefix++;
        $end=0;while($end<$n-$prefix&&$end<$m-$prefix&&$a[$n-1-$end]===$b[$m-1-$end])$end++;
        $a=array_slice($a,$prefix,$n-$prefix-$end);$b=array_slice($b,$prefix,$m-$prefix-$end);$n=count($a);$m=count($b);
        $out=[];$i=0;$j=0;$added=0;$removed=0;
        // Exact LCS for ordinary edits; bounded replacement hunk for large changes.
        $exact=($n+1)*($m+1)<=250000;
        $dp=[];if($exact){$dp=array_fill(0,$n+1,array_fill(0,$m+1,0));for($i=$n-1;$i>=0;$i--)for($j=$m-1;$j>=0;$j--)$dp[$i][$j]=$a[$i]===$b[$j]?1+$dp[$i+1][$j+1]:max($dp[$i+1][$j],$dp[$i][$j+1]);}
        $i=$j=0;
        while($i<$n||$j<$m){
            if($exact&&$i<$n&&$j<$m&&$a[$i]===$b[$j]){$out[]=['op'=>'context','old_line'=>$prefix+$i+1,'new_line'=>$prefix+$j+1,'text'=>$a[$i]];$i++;$j++;}
            elseif($i<$n&&(!$exact||$j===$m||$dp[$i+1][$j]>=$dp[$i][$j+1])){$out[]=['op'=>'remove','old_line'=>$prefix+$i+1,'new_line'=>null,'text'=>$a[$i++]];$removed++;}
            else{$out[]=['op'=>'add','old_line'=>null,'new_line'=>$prefix+$j+1,'text'=>$b[$j++]];$added++;}
        }
        return ['added'=>$added,'removed'=>$removed,'unchanged_prefix'=>$prefix,'unchanged_suffix'=>$end,'exact'=>$exact,'lines'=>$out];
    }
}
