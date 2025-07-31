<?php
declare(strict_types=1);

namespace Src\Service;

use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Src\Config\Config;
use Psr\Log\LoggerInterface;
use Src\Service\LoggerService;

class TelegramService
{
    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = LoggerService::getLogger();

        try {
            new Telegram(
                Config::get('TELEGRAM_BOT_TOKEN'),
                Config::get('TELEGRAM_BOT_NAME')
            );
        } catch (TelegramException $e) {
            // Log initialization failure but allow execution to continue so
            // sendMessage() will fail gracefully and log the error there.
            $this->logger->error('Telegram init failed: ' . $e->getMessage());
        }
    }

    public function sendMessage(int $chatId, string $text): void
    {
        try {
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]);
            $this->logger->info('Sent message to Telegram', ['chat_id' => $chatId]);
        } catch (TelegramException $e) {
            $this->logger->error('Telegram sendMessage failed: ' . $e->getMessage());
        }
    }
}
