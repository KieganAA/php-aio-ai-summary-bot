<?php
declare(strict_types=1);

namespace Src\Config;

use Dotenv\Dotenv;

class Config
{
    private static array $env;

    public static function load(string $projectRoot): void
    {
        $dotenv = Dotenv::createImmutable($projectRoot);
        $dotenv->load();
        self::$env = $_ENV;
    }

    public static function get(string $key): string
    {
        return self::$env[$key] ?? '';
    }
}
