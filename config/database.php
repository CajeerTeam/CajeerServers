<?php
return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'recommended' => 'pgsql:18+',
    'connections' => [
        'pgsql' => ['driver' => 'pgsql', 'version' => '17+', 'host' => env('DB_HOST', '127.0.0.1'), 'port' => env('DB_PORT', 5432)],
        'mysql' => ['driver' => 'mysql', 'version' => '8.4+'],
        'mariadb' => ['driver' => 'mysql', 'version' => '10.11+'],
    ],
];
