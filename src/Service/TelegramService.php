<?php
declare(strict_types=1);

namespace Src\Service;

use Longman\TelegramBot\Entities\ServerResponse;
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

    /**
     * Send a message to Telegram handling length limits and error logging.
     *
     * Telegram messages have a maximum length of 4096 characters. Longer
     * messages are split into multiple chunks and sent sequentially. The
     * method returns the response of the last chunk sent.
     */
    public function sendMessage(int $chatId, string $text, string $parseMode = 'Markdown'): ServerResponse
    {
        $maxLength = 4096;
        $response = Request::emptyResponse();

        $length = mb_strlen($text);
        for ($offset = 0; $offset < $length; $offset += $maxLength) {
            $chunk = mb_substr($text, $offset, $maxLength);

            try {
                $response = Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $chunk,
                    'parse_mode' => $parseMode,
                ]);

                if ($response->isOk()) {
                    $this->logger->info('Sent message to Telegram', ['chat_id' => $chatId]);
                } else {
                    $this->logger->error('Telegram sendMessage failed', [
                        'chat_id' => $chatId,
                        'error' => $response->getDescription(),
                    ]);
                }
            } catch (TelegramException $e) {
                $this->logger->error('Telegram sendMessage failed: ' . $e->getMessage());
                break;
            }
        }

        return $response;
    }
}
