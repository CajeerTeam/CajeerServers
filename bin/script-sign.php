#!/usr/bin/env php
<?php
declare(strict_types=1);
// Offline signer: no application bootstrap, DB, Vault, network or private key in argv.
if(PHP_SAPI!=='cli')exit(1);
try {
    if($argc===3&&$argv[1]==='generate') {
        $pair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($pair);$public=sodium_crypto_sign_publickey($pair);sodium_memzero($pair);
        $mask=umask(0077);$f=@fopen($argv[2],'xb');umask($mask);if(!$f)throw new RuntimeException('Cannot create new key file.');
        try{if(fwrite($f,bin2hex($secret)."\n")!==129||!fflush($f))throw new RuntimeException('Key write failed.');}finally{fclose($f);sodium_memzero($secret);}
        echo bin2hex($public),"\n";exit(0);
    }
    if($argc===4&&$argv[1]==='sign') {
        $path=$argv[2];if(is_link($path)||!is_file($path)||(fileperms($path)&0077)!==0)throw new RuntimeException('Private key must be a regular owner-only file.');
        $hex=trim((string)file_get_contents($path,false,null,0,130));if(strlen($hex)!==128||!ctype_xdigit($hex))throw new RuntimeException('Invalid private key.');$secret=hex2bin($hex);sodium_memzero($hex);
        $bytes=file_get_contents($argv[3],false,null,0,131073);if($bytes===false||strlen($bytes)>131072||$bytes==='')throw new RuntimeException('Invalid signing bytes.');
        try{echo bin2hex(sodium_crypto_sign_detached($bytes,$secret)),"\n";}finally{sodium_memzero($secret);}exit(0);
    }
    fwrite(STDERR,"Usage: php bin/script-sign.php generate PRIVATE_FILE\n       php bin/script-sign.php sign PRIVATE_FILE SIGNING_BYTES_FILE\n");exit(2);
}catch(Throwable $e){fwrite(STDERR,"Signing failed. Check local file permissions and input.\n");exit(1);}
