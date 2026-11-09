<?php
declare(strict_types=1);

use Cajeer\Servers\Config\EnvironmentLoader;

if (!function_exists('env')) {
    /** Read a typed process/.env value, without writing it to an HTTP response. */
    function env(string $key, mixed $default = null): mixed
    {
        return EnvironmentLoader::get($key, $default);
    }
}
