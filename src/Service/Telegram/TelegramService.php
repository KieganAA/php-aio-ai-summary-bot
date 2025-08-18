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
use Src\Util\TextUtils;

class TelegramService
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? LoggerService::getLogger();

        try {
            // Ensure the SDK is configured (Request uses internal Telegram instance)
            new Telegram(
                Config::get('TELEGRAM_BOT_TOKEN'),
                Config::get('TELEGRAM_BOT_NAME')
            );
        } catch (TelegramException $e) {
            $this->logger->error('Telegram init failed: ' . $e->getMessage());
        }
    }

    /**
     * Send a (possibly long) message with safe chunking.
     * - Splits on paragraphs/lines to respect 4096 limit.
     * - Avoids trailing "\" in chunks (MarkdownV2 parse error).
     * - On "can't parse entities" fallback sends plain text (no parse_mode).
     * - By default disables link previews (can be overridden via $extra).
     */
    public function sendMessage(
        int    $chatId,
        string $text,
        string $parseMode = 'MarkdownV2',
        array  $extra = []
    ): ServerResponse
    {
        $maxLength = 4096;
        $budget = 3900; // safety margin for escapes, etc.

        if (!array_key_exists('disable_web_page_preview', $extra)) {
            $extra['disable_web_page_preview'] = true;
        }

        $chunks = TextUtils::splitForTelegram($text, $budget);
        $response = Request::emptyResponse();

        foreach ($chunks as $i => $chunk) {
            // Telegram hard limit guard (final safety)
            if (mb_strlen($chunk, 'UTF-8') > $maxLength) {
                $chunk = mb_substr($chunk, 0, $maxLength, 'UTF-8');
                // avoid ending with "\" (MarkdownV2)
                while (mb_substr($chunk, -1, 1, 'UTF-8') === '\\') {
                    $chunk = mb_substr($chunk, 0, -1, 'UTF-8');
                }
            }

            $params = array_merge([
                'chat_id' => $chatId,
                'text' => $chunk,
            ], $extra);

            if ($parseMode !== '') {
                $params['parse_mode'] = $parseMode;
            }

            try {
                $response = Request::sendMessage($params);

                if (!$response->isOk() && $parseMode !== '') {
                    // Typical formatting error: "can't parse entities"
                    $desc = (string)$response->getDescription();
                    if (stripos($desc, "can't parse entities") !== false || stripos($desc, 'parse') !== false) {
                        $this->logger->warning('Markdown parse failed, falling back to plain text', [
                            'chat_id' => $chatId,
                            'desc' => $desc,
                        ]);

                        // Fallback: plain text (no parse_mode)
                        $plain = TextUtils::toPlainText($chunk);
                        $paramsFallback = $params;
                        unset($paramsFallback['parse_mode']);
                        $paramsFallback['text'] = $plain;

                        $response = Request::sendMessage($paramsFallback);
                    }
                }

                if ($response->isOk()) {
                    $this->logger->info('Sent Telegram chunk', ['chat_id' => $chatId, 'index' => $i + 1, 'count' => count($chunks)]);
                } else {
                    $this->logger->error('Telegram sendMessage failed', [
                        'chat_id' => $chatId,
                        'error' => $response->getDescription(),
                    ]);
                }
            } catch (TelegramException $e) {
                $this->logger->error('Telegram sendMessage exception: ' . $e->getMessage(), [
                    'chat_id' => $chatId,
                ]);
                // Do not break hard; try next chunks? Usually better to stop.
                break;
            }

            // Tiny pacing to be gentle with rate limits
            usleep(80_000);
        }

        return $response;
    }
}
