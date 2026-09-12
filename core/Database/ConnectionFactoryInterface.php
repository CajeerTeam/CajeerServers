<?php
namespace Cajeer\Servers\Database;

interface ConnectionFactoryInterface
{
    public function make(array $config): \PDO;
}
