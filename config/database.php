<?php
declare(strict_types=1);

use Cajeer\Servers\Config\EnvironmentLoader;

return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'recommended' => 'pgsql:18+',
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => EnvironmentLoader::getString('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 5432),
            'database' => EnvironmentLoader::getString('DB_DATABASE', 'cajeerservers'),
            'username' => EnvironmentLoader::getString('DB_USERNAME', 'cajeer'),
            'password' => EnvironmentLoader::getString('DB_PASSWORD', ''),
            'sslmode' => EnvironmentLoader::getString('DB_SSLMODE', 'prefer'),
            'sslrootcert' => EnvironmentLoader::getString('DB_SSLROOTCERT', ''),
            'connect_timeout' => env('DB_CONNECT_TIMEOUT', 5),
        ],
        // Connection support is available; migration parity is not part of 0.1.3.
        'mysql' => [
            'driver' => 'mysql',
            'host' => EnvironmentLoader::getString('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 3306),
            'database' => EnvironmentLoader::getString('DB_DATABASE', 'cajeerservers'),
            'username' => EnvironmentLoader::getString('DB_USERNAME', 'cajeer'),
            'password' => EnvironmentLoader::getString('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'connect_timeout' => env('DB_CONNECT_TIMEOUT', 5),
        ],
        'mariadb' => [
            'driver' => 'mysql',
            'host' => EnvironmentLoader::getString('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 3306),
            'database' => EnvironmentLoader::getString('DB_DATABASE', 'cajeerservers'),
            'username' => EnvironmentLoader::getString('DB_USERNAME', 'cajeer'),
            'password' => EnvironmentLoader::getString('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'connect_timeout' => env('DB_CONNECT_TIMEOUT', 5),
        ],
    ],
];
