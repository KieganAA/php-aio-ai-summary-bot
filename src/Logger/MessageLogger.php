<?php
declare(strict_types=1);

namespace Src\Logger;

use Longman\TelegramBot\Entities\Update;
use Psr\Log\LoggerInterface;
use Src\Config\Config;
use Src\Entity\Message;
use Src\Repository\MessageRepositoryInterface;
use Src\Service\LoggerService;

/**
 * Handles incoming Telegram updates and persists relevant messages.
 */
class MessageLogger
{
    private LoggerInterface $logger;

    public function __construct(
        private MessageRepositoryInterface $repository
    ) {
        $this->logger = LoggerService::getLogger();
    }

    /**
     * Process an incoming update.
     */
    public function handleUpdate(Update $update): void
    {
        $message = $update->getMessage();
        if ($message === null) {
            return; // ignore non-message updates
        }

        $chat = $message->getChat();
        $title = $chat->getTitle();
        if ($title === null || stripos($title, 'AIO') === false) {
            // Only process chats containing "AIO" in the title
            return;
        }

        $attachments = [];
        if ($message->getPhoto()) {
            $attachments['photo'] = true;
        }
        if ($message->getDocument()) {
            $attachments['document'] = $message->getDocument()->getFileName();
        }
        $attachmentsJson = !empty($attachments) ? json_encode($attachments) : null;

        $msg = new Message(
            $chat->getId(),
            $title,
            $message->getMessageId(),
            $message->getFrom()?->getUsername() ?? '' ,
            $message->getDate(),
            $message->getText() ?? '',
            $attachmentsJson
        );

        $this->repository->add($msg->chatId, [
            'message_id'  => $msg->messageId,
            'chat_title'  => $msg->chatTitle,
            'from'        => ['username' => $msg->fromUser],
            'date'        => $msg->messageDate,
            'text'        => $msg->text,
            'attachments' => $msg->attachments,
        ]);

        $this->logger->info('Message logged', ['chat' => $title, 'id' => $msg->messageId]);
    }
}
