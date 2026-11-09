<?php
declare(strict_types=1);

use Cajeer\Servers\Config\EnvironmentLoader;

return [
    'default' => env('QUEUE_CONNECTION', 'redis'),
    'redis_prefix' => EnvironmentLoader::getString('REDIS_QUEUE_PREFIX', 'cajeer:queue:'),
    'default_queue' => EnvironmentLoader::getString('QUEUE_NAME', 'default'),
    'lease_seconds' => (int) env('QUEUE_LEASE_SECONDS', 60),
];
