<?php
declare(strict_types=1);

// Local integration fixture only; recomputes SigV4 independently and persists objects to a temp directory.
require dirname(__DIR__,2).'/core/autoload.php';

use Cajeer\Servers\Storage\LocalDisk;

$path=parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$headers=[];
foreach (getallheaders() as $name=>$value) $headers[strtolower($name)]=$value;
$auth=$headers['authorization']??'';
if (!is_string($path) || !preg_match('~^/test-bucket/(.+)$~',$path,$match)
 || !preg_match('~^AWS4-HMAC-SHA256 Credential=testaccess/(\d{8})/(us-east-1)/s3/aws4_request, SignedHeaders=([a-z0-9;-]+), Signature=([a-f0-9]{64})$~',$auth,$a)) {
    http_response_code(403);exit;
}
$payload=file_get_contents('php://input');
if (!hash_equals(hash('sha256',$payload), $headers['x-amz-content-sha256']??'')) {http_response_code(403);exit;}
$names=explode(';',$a[3]);$canon='';
foreach ($names as $name) {
 if (!isset($headers[$name])) {http_response_code(403);exit;}
 $canon.=$name.':'.trim($headers[$name])."\n";
}
$scope=$a[1].'/'.$a[2].'/s3/aws4_request';
$request=implode("\n",[$_SERVER['REQUEST_METHOD'],$path,'',$canon,$a[3],hash('sha256',$payload)]);
$toSign="AWS4-HMAC-SHA256\n".($headers['x-amz-date']??'')."\n".$scope."\n".hash('sha256',$request);
$kDate=hash_hmac('sha256',$a[1],'AWS4testsecret',true);
$kRegion=hash_hmac('sha256',$a[2],$kDate,true);
$kService=hash_hmac('sha256','s3',$kRegion,true);
$kSigning=hash_hmac('sha256','aws4_request',$kService,true);
if (!hash_equals($a[4],hash_hmac('sha256',$toSign,$kSigning))) {http_response_code(403);exit;}
$key=implode('/',array_map('rawurldecode',explode('/',$match[1])));
try {
 $disk=new LocalDisk((string)getenv('CAJEER_S3_FIXTURE_ROOT'));
 switch($_SERVER['REQUEST_METHOD']) {
 case 'PUT': $disk->put($key,$payload); http_response_code(200); break;
 case 'GET': if (!$disk->exists($key)) {http_response_code(404);break;} echo $disk->get($key);break;
 case 'HEAD': http_response_code($disk->exists($key)?200:404);break;
 case 'DELETE': $disk->delete($key);http_response_code(204);break;
 default: http_response_code(405);
 }
} catch (Throwable) {http_response_code(400);}
