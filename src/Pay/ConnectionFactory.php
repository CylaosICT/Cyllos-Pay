<?php

namespace App\Pay;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

final class ConnectionFactory
{
    public static function create(string $dsn): Connection
    {
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'sqlite' => 'pdo_sqlite']))->parse($dsn);

        return DriverManager::getConnection($params);
    }
}
