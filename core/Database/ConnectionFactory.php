<?php
namespace Cajeer\Servers\Database;

final class ConnectionFactory implements ConnectionFactoryInterface
{
    public function make(array $config): \PDO
    {
        $dsn = $config['dsn'] ?? 'pgsql:host=127.0.0.1;port=5432;dbname=cajeerservers';
        return new \PDO($dsn, $config['username'] ?? 'cajeer', $config['password'] ?? '');
    }
}
