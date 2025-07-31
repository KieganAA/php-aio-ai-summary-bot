<?php
declare(strict_types=1);

namespace Src\Service;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\SQLLogger;
use Psr\Log\LoggerInterface;
use Src\Config\Config;

class Database
{
    private static ?Connection $connection = null;

    public static function setConnection(Connection $connection): void
    {
        self::$connection = $connection;
    }

    public static function getConnection(LoggerInterface $logger): Connection
    {
        if (self::$connection === null) {
            $params = [
                'dbname'   => Config::get('DB_NAME'),
                'user'     => Config::get('DB_USER'),
                'password' => Config::get('DB_PASS'),
                'host'     => Config::get('DB_HOST'),
                'driver'   => 'pdo_mysql',
                'charset'  => 'utf8mb4',
            ];
            $config = new Configuration();
            $config->setSQLLogger(new class($logger) implements SQLLogger {
                public function __construct(private LoggerInterface $logger) {}
                public function startQuery(string $sql, ?array $params = null, ?array $types = null): void
                {
                    $this->logger->debug('SQL', ['sql' => $sql, 'params' => $params]);
                }
                public function stopQuery(): void {}
            });
            self::$connection = DriverManager::getConnection($params, $config);
        }
        return self::$connection;
    }
}
