<?php
declare(strict_types=1);

use Cajeer\Servers\Config\EnvironmentLoader;

return [
    'default' => env('CACHE_DRIVER', 'redis'),
    'redis' => [
        'host' => EnvironmentLoader::getString('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'database' => (int) env('REDIS_DB', 0),
        'username' => EnvironmentLoader::getString('REDIS_USERNAME', ''),
        'password' => EnvironmentLoader::getString('REDIS_PASSWORD', ''),
        'tls' => env('REDIS_TLS', false) === true,
        'ca_file' => EnvironmentLoader::getString('REDIS_CA_FILE', ''),
        'timeout' => (float) env('REDIS_TIMEOUT', 3),
        'prefix' => EnvironmentLoader::getString('REDIS_CACHE_PREFIX', 'cajeer:cache:'),
    ],
];
