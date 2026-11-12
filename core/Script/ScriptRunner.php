<?php
declare(strict_types=1);
namespace Cajeer\Servers\Script;

use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Http\Exception\HttpException;

/** Local execution only. No shell interpolation, network, application filesystem or inherited environment. */
final class ScriptRunner
{
    public function __construct(private readonly ConfigRepository $config) {}

    private static function fail(string $code): never
    { throw new HttpException(503,'Script execution failed or sandbox unavailable.',$code); }

    /** Require aggregate memory/task/CPU ceilings in cgroup v2, not just per-process rlimits. */
    private static function requireCgroup():void
    {
        $raw=@file_get_contents('/proc/self/cgroup');if(!is_string($raw)||!preg_match('/^0::(\/[^\r\n]*)$/m',$raw,$m))self::fail('script_cgroup_unbounded');
        $base='/sys/fs/cgroup';$path=$base.rtrim($m[1],'/');$mem=PHP_INT_MAX;$swap=PHP_INT_MAX;$tasks=PHP_INT_MAX;$cpu=INF;
        while(str_starts_with($path,$base)){
            $memory=trim((string)@file_get_contents($path.'/memory.max'));$swapLimit=trim((string)@file_get_contents($path.'/memory.swap.max'));$pids=trim((string)@file_get_contents($path.'/pids.max'));$quota=trim((string)@file_get_contents($path.'/cpu.max'));
            if(ctype_digit($memory))$mem=min($mem,(int)$memory);if(ctype_digit($swapLimit))$swap=min($swap,(int)$swapLimit);if(ctype_digit($pids))$tasks=min($tasks,(int)$pids);
            if(preg_match('/^([0-9]+) ([1-9][0-9]*)$/D',$quota,$q))$cpu=min($cpu,(int)$q[1]/(int)$q[2]);
            if($path===$base)break;$path=dirname($path);
        }
        if($mem>1073741824||$swap>268435456||$tasks>128||$cpu>2)self::fail('script_cgroup_unbounded');
    }

    private function command(string $sourceFile,array $manifest):array
    {
        self::requireCgroup();
        $root=(string)$this->config->get('scripts.rootfs','');$real=realpath($root);
        if ($root==='' || $real===false || $real!==$root || $real==='/' || !is_dir($real) || fileowner($real)!==0 || (fileperms($real)&0022)!==0
            || !is_executable('/usr/bin/bwrap') || fileowner('/usr/bin/bwrap')!==0 || (fileperms('/usr/bin/bwrap')&04022)!==0
            || !is_executable('/usr/bin/prlimit') || fileowner('/usr/bin/prlimit')!==0 || (fileperms('/usr/bin/prlimit')&0022)!==0 || !function_exists('posix_geteuid') || posix_geteuid()===0) self::fail('script_sandbox_unavailable');
        // Require an explicitly provisioned root, not a shared host or application directory.
        $marker=$real.'/.cajeer-script-root';
        if(!is_file($marker)||is_link($marker)||fileowner($marker)!==0||(fileperms($marker)&0022)!==0||filesize($marker)!==strlen("cajeer-script-root-v1\n")||file_get_contents($marker)!=="cajeer-script-root-v1\n")self::fail('script_sandbox_unavailable');
        for($parent=dirname($real);$parent!=='/';$parent=dirname($parent))if(fileowner($parent)!==0||(fileperms($parent)&0022)!==0)self::fail('script_sandbox_unavailable');
        // A nested bind mount could unintentionally bring host paths/sockets into the sandbox.
        $mounts=@file('/proc/self/mountinfo',FILE_IGNORE_NEW_LINES);if($mounts===false)self::fail('script_sandbox_unavailable');
        foreach($mounts as $line){$fields=explode(' ',$line);$mount=stripcslashes($fields[4]??'');if(str_starts_with($mount,$real.'/'))self::fail('script_sandbox_unavailable');}
        foreach (['/proc','/dev','/tmp','/work'] as $dir) if (!is_dir($real.$dir) || is_link($real.$dir)) self::fail('script_sandbox_unavailable');
        $interpreter=match($manifest['language']) {
            'bash'=>['/usr/bin/bash','--noprofile','--norc','/work/script'],
            'python'=>['/usr/bin/python3','-I','-B','/work/script'],
            'powershell'=>['/usr/bin/pwsh','-NoLogo','-NoProfile','-NonInteractive','-File','/work/script.ps1'],
            default=>throw new HttpException(422,'Unsupported local script language.','script_policy_denied'),
        };
        $dest=$manifest['language']==='powershell'?'/work/script.ps1':'/work/script';
        return ['/usr/bin/prlimit','--cpu='.($manifest['timeout_seconds']+1),'--as=8589934592','--fsize=16777216','--nofile=128','--nproc=64','--core=0','--',
            '/usr/bin/bwrap','--unshare-all','--unshare-user','--disable-userns','--hostname','cajeer-script','--die-with-parent','--new-session','--cap-drop','ALL',
            '--ro-bind',$real,'/','--proc','/proc','--dev','/dev','--perms','1777','--size','16777216','--tmpfs','/tmp','--perms','1777','--size','1048576','--tmpfs','/work',
            '--ro-bind',$sourceFile,$dest,'--chdir','/work','--clearenv','--setenv','PATH','/usr/bin:/bin','--setenv','HOME','/tmp',
            '--setenv','LANG','C.UTF-8','--setenv','TMPDIR','/tmp','--setenv','POWERSHELL_TELEMETRY_OPTOUT','1','--setenv','DOTNET_GCHeapHardLimit','0x10000000','--',...$interpreter];
    }

    public function run(string $source,array $manifest,array $parameters,callable $guard):array
    {
        if (PHP_SAPI!=='cli' || $this->config->get('scripts.enabled')!==true || ScriptExecutionPolicy::channel($manifest,$source)!=='sandbox') self::fail('script_execution_disabled');
        $dir=sys_get_temp_dir().'/cajeer-script-'.bin2hex(random_bytes(16));
        if (!mkdir($dir,0700)) self::fail('script_sandbox_unavailable');
        $file=$dir.'/source';$proc=null;$pipes=[];
        try {
            if (file_put_contents($file,$source)!==strlen($source) || !chmod($file,0600)) self::fail('script_sandbox_unavailable');
            $cmd=$this->command($file,$manifest);$guard();
            $proc=@proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$dir,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8']);
            if (!is_resource($proc)) self::fail('script_sandbox_unavailable');
            return $this->exchange($proc,$pipes,json_encode((object)$parameters,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n",$manifest['timeout_seconds'],$manifest['output_limit_bytes'],$guard,$manifest);
        } finally {
            // Killing bwrap's supervisor also kills its PID namespace (die-with-parent).
            if (is_resource($proc)) { $s=proc_get_status($proc);if($s['running'])proc_terminate($proc,9); }
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
            if(is_resource($proc))proc_close($proc);
            if(is_file($file))unlink($file);rmdir($dir);
        }
    }

    /** Nonblocking bounded duplex I/O. stderr is counted but never persisted. */
    private function exchange($proc,array &$pipes,string $input,int $seconds,int $limit,callable $guard,array $manifest=[]):array
    {
        foreach($pipes as $pipe)stream_set_blocking($pipe,false);
        $deadline=hrtime(true)+$seconds*1_000_000_000;$nextGuard=0;$out='';$total=0;$sent=0;$exit=null;
        while (true) {
            $now=hrtime(true);if($now>=$deadline)self::fail('script_timeout');
            if($now>=$nextGuard){$guard();$nextGuard=$now+1_000_000_000;}
            $read=[];foreach([1,2] as $i)if(isset($pipes[$i]))$read[]=$pipes[$i];
            $write=isset($pipes[0])?[$pipes[0]]:[];$except=[];
            if($read||$write){if(@stream_select($read,$write,$except,0,100000)===false)self::fail('script_io_error');}else usleep(10000);
            if($write){$n=@fwrite($pipes[0],substr($input,$sent,8192));if($n===false)self::fail('script_io_error');$sent+=$n;if($sent===strlen($input)){fclose($pipes[0]);unset($pipes[0]);}}
            foreach([1,2] as $i){if(!isset($pipes[$i])||!in_array($pipes[$i],$read,true))continue;
                $chunk=fread($pipes[$i],8192);if($chunk===false)self::fail('script_io_error');$total+=strlen($chunk);if($total>$limit)self::fail('script_output_limit');if($i===1)$out.=$chunk;
                if(feof($pipes[$i])){fclose($pipes[$i]);unset($pipes[$i]);}}
            $status=proc_get_status($proc);if(!$status['running']&&$exit===null)$exit=$status['exitcode'];
            if($exit!==null&&!isset($pipes[1])&&!isset($pipes[2]))break;
        }
        if($exit!==0)self::fail('script_exit_failed');
        $guard();
        $result=(new ScriptOutputParser())->parse($out,$manifest+['output_limit_bytes'=>$limit]);
        $guard();return $result;
    }
}
