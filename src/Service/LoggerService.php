<?php
declare(strict_types=1);

namespace Src\Service;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Src\Config\Config;

class LoggerService
{
    private static ?LoggerInterface $logger = null;

    public static function getLogger(): LoggerInterface
    {
        if (self::$logger === null) {
            $logger = new Logger('bot');
            // Set the static instance before adding handlers to avoid
            // recursive calls when handlers depend on the logger.
            self::$logger = $logger;

            $logFile = __DIR__ . '/../../logs/app.log';
            $logDir  = dirname($logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0777, true);
            }

            $logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));

            $token = Config::get('TELEGRAM_BOT_TOKEN');
            $name  = Config::get('TELEGRAM_BOT_NAME');
            if ($token !== '' && $name !== '') {
                $chatIdEnv = Config::get('LOG_CHAT_ID');
                $chatId    = $chatIdEnv !== '' ? (int)$chatIdEnv : -1002671594630;
                $logger->pushHandler(new TelegramLogHandler($chatId, Logger::ERROR));
            }
        }
        return self::$logger;
    }
}
