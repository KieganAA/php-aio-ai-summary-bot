<?php
declare(strict_types=1);

namespace Src\Service;

use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

class TelegramService
{
    public function __construct()
    { /* nothing */
    }

    public function sendMessage(int $chatId, string $text): void
    {
        try {
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            error_log('Telegram sendMessage failed: ' . $e->getMessage());
        }
    }
}
