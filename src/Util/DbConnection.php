<?php
declare(strict_types=1);

namespace Src\Util;

use PDO;
use Src\Config\Config;

class DbConnection
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                Config::get('DB_HOST'),
                Config::get('DB_NAME')
            );
            self::$pdo = new PDO(
                $dsn,
                Config::get('DB_USER'),
                Config::get('DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }
        return self::$pdo;
    }
}
