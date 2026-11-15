<?php
declare(strict_types=1);
/** Test-only disposable database provisioning. Never connects to an application .env. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Database\ConnectionFactory;
$op=$argv[1]??''; $name=$argv[2]??'';
if (getenv('CAJEER_PG_TEST')!=='1'||!preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')||!preg_match('/^(?:cajeer_test_platform_|cajeer_drill_platform_)[a-f0-9]{24}$/D',$name)||!in_array($op,['create','drop'],true)||!extension_loaded('pdo_pgsql')) {
    fwrite(STDERR,"Explicit disposable PostgreSQL configuration required.\n");exit(2);
}
try {
    $pdo=(new ConnectionFactory())->make(['driver'=>'pgsql','host'=>getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1','port'=>getenv('CAJEER_PG_TEST_PORT')?:'5432','database'=>'postgres','username'=>getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer','password'=>getenv('CAJEER_PG_TEST_PASSWORD')?:'','sslmode'=>getenv('CAJEER_PG_TEST_SSLMODE')?:'disable']);
    if ((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()<170000)throw new RuntimeException('PostgreSQL 17+ required');
    $pdo->exec($op==='create'?'CREATE DATABASE "'.$name.'"':'DROP DATABASE "'.$name.'" WITH (FORCE)');
    echo "$op disposable database OK\n";
} catch(Throwable $error) {fwrite(STDERR,'Database provisioning failed: '.$error::class."\n");exit(1);}
