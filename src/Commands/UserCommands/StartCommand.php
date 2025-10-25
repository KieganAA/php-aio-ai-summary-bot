<?php
declare(strict_types=1);

namespace Src\Commands\UserCommands;

use Exception;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Psr\Log\LoggerInterface;
use Src\Service\LoggerService;

class StartCommand extends UserCommand
{
    protected $name = 'start';
    protected $description = 'Start command: creates a private channel invite link';
    protected $usage = '/start <uuid>';
    protected $version = '1.5.0';

    private LoggerInterface $logger;

    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->logger = LoggerService::getLogger();
    }

    /**
     * @return ServerResponse
     * @throws TelegramException
     */
    public function execute(): ServerResponse
    {
        $msg = $this->getMessage();
        $chatId = $msg->getChat()->getId();
        $from = $msg->getFrom();
        $tgId = $from ? (int)$from->getId() : 0;

        $visitUuid = trim($msg->getText(true) ?? '');
        if ($visitUuid === '') {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Missing UUID. Use /start <uuid>.',
            ]);
        }

        $channelId = -1003202647565;
        if ($channelId === 0) {
            $this->logger->error('TG_TARGET_CHANNEL_ID not set');
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Channel not configured.',
            ]);
        }

        // Try with the raw UUID as name (Telegram allows up to 32 chars; UUID(36) may fail).
        $namePrimary = $visitUuid;
        $nameFallback = strtolower(str_replace('-', '', $visitUuid)); // 32 hex, safe

        try {
            $inviteLink = $this->createInviteLink($channelId, $namePrimary);
        } catch (Exception $e) {
            // Fallback to 32-hex name if name too long/invalid
            $this->logger->warning('Primary invite name failed, trying fallback', [
                'error' => $e->getMessage(),
                'name' => $namePrimary,
            ]);
            $inviteLink = $this->createInviteLink($channelId, $nameFallback);
        }

        $kb = new InlineKeyboard([
            new InlineKeyboardButton([
                'text' => 'Join the channel',
                'url' => $inviteLink,
            ]),
        ]);

        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => 'Tap to join the channel.',
            'reply_markup' => $kb,
        ]);
    }

    /**
     * Create a single-use invite link with short expiry.
     */
    private function createInviteLink(int $channelId, string $name): string
    {
        $payload = [
            'chat_id' => $channelId,
            'member_limit' => 1,
            'expire_date' => time() + 10 * 60,
        ];
        // Telegram: name length 0..32. If yours is longer it will error.
        if ($name !== '' && strlen($name) <= 32) {
            $payload['name'] = $name;
        }

        $resp = Request::createChatInviteLink($payload);
        if (!$resp->isOk()) {
            throw new Exception('createChatInviteLink failed: ' . $resp->getDescription());
        }
        return $resp->getResult()->getInviteLink();
    }
}
