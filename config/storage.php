<?php
declare(strict_types=1);

use Cajeer\Servers\Config\EnvironmentLoader;

return [
    'default' => env('STORAGE_DISK', 'local'),
    'local' => ['root' => EnvironmentLoader::getString('STORAGE_LOCAL_ROOT', '')],
    's3' => [
        'endpoint' => EnvironmentLoader::getString('S3_ENDPOINT', ''),
        'bucket' => EnvironmentLoader::getString('S3_BUCKET', ''),
        'region' => EnvironmentLoader::getString('S3_REGION', 'us-east-1'),
        'access_key' => EnvironmentLoader::getString('S3_ACCESS_KEY', ''),
        'secret_key' => EnvironmentLoader::getString('S3_SECRET_KEY', ''),
        'session_token' => EnvironmentLoader::getString('S3_SESSION_TOKEN', ''),
        'allow_local_http' => env('S3_ALLOW_LOCAL_HTTP', false) === true,
        'timeout' => (int) env('S3_TIMEOUT', 10),
    ],
];
