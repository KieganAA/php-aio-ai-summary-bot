<?php
declare(strict_types=1);

namespace Src;

use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Telegram;
use Src\Config\Config;
use Src\Service\LoggerService;

class BotHandle
{
    /**
     * @throws TelegramException
     */
    public static function run(): void
    {
        Config::load(__DIR__ . '/../');
        $logger = LoggerService::getLogger();
        $logger->info('Bot starting');

        $telegram = new Telegram(
            Config::get('TELEGRAM_BOT_TOKEN'),
            Config::get('TELEGRAM_BOT_NAME')
        );

        $telegram->addCommandsPath(__DIR__ . '/Commands/SystemCommands');
        $telegram->addCommandsPath(__DIR__ . '/Commands/UserCommands');

        $telegram->handle();
        $logger->info('Bot handled request');
    }
}
