<?php
declare(strict_types=1);

namespace Src\Service;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class LoggerService
{
    private static ?LoggerInterface $logger = null;

    public static function getLogger(): LoggerInterface
    {
        if (self::$logger === null) {
            $logger = new Logger('bot');
            $logFile = __DIR__ . '/../../logs/app.log';
            $logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));
            $logger->pushHandler(new TelegramLogHandler(Logger::ERROR));
            self::$logger = $logger;
        }
        return self::$logger;
    }
}
