<?php
declare(strict_types=1);
namespace Cajeer\Servers\Events;

/** RFC6587 octet counting or newline framing; bounded across fragmented reads. */
final class SyslogFramer
{
    private string $buffer='';
    private ?bool $octet=null;
    public function push(string $chunk):array
    {
        $this->buffer.=$chunk;$out=[];
        while($this->buffer!==''){
            $this->octet??=ctype_digit($this->buffer[0]);
            if($this->octet){
                $space=strpos($this->buffer,' ');
                if($space===false){if(strlen($this->buffer)>5||!ctype_digit($this->buffer))throw new \InvalidArgumentException('Invalid frame length.');break;}
                $prefix=substr($this->buffer,0,$space);if(!preg_match('/^[1-9][0-9]{0,4}$/D',$prefix)||(int)$prefix>16384)throw new \InvalidArgumentException('Invalid frame length.');
                $length=(int)$prefix;if(strlen($this->buffer)<$space+1+$length)break;
                $out[]=substr($this->buffer,$space+1,$length);$this->buffer=substr($this->buffer,$space+1+$length);
            }else{
                $end=strpos($this->buffer,"\n");if($end===false)break;if($end>16384)throw new \InvalidArgumentException('Frame too large.');$out[]=rtrim(substr($this->buffer,0,$end),"\r");$this->buffer=substr($this->buffer,$end+1);
            }
        }
        if(strlen($this->buffer)>16390)throw new \InvalidArgumentException('Frame too large.');return $out;
    }
}
