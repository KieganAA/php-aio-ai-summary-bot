<?php
declare(strict_types=1);

namespace Src\Service\Telegram;

use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Psr\Log\LoggerInterface;
use Src\Config\Config;
use Src\Service\LoggerService;

class TelegramService
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? LoggerService::getLogger();

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
    public function sendMessage(int $chatId, string $text, string $parseMode = 'Markdown', array $extra = []): ServerResponse
    {
        $maxLength = 4096;
        $response = Request::emptyResponse();

        $length = mb_strlen($text);
        for ($offset = 0; $offset < $length;) {
            $take = min($maxLength, $length - $offset);
            $chunk = mb_substr($text, $offset, $take);

            if ($parseMode === 'MarkdownV2') {
                while (mb_substr($chunk, -1) === '\\') {
                    $take--;
                    $chunk = mb_substr($text, $offset, $take);
                }
            }

            $params = array_merge([
                'chat_id' => $chatId,
                'text'   => $chunk,
            ], $extra);
            if ($parseMode !== '') {
                $params['parse_mode'] = $parseMode;
            }

            try {
                $response = Request::sendMessage($params);

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

            $offset += $take;
        }

        return $response;
    }
}
