<?php
function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}
