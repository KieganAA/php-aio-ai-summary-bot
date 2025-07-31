<?php
declare(strict_types=1);

namespace Src\Logger;

use Longman\TelegramBot\Entities\Update;
use Psr\Log\LoggerInterface;
use Src\Entity\Message;
use Src\Repository\MessageRepositoryInterface;

/**
 * Handles incoming Telegram updates and persists relevant messages.
 */
class MessageLogger
{
    private string $chatPattern;

    public function __construct(
        private MessageRepositoryInterface $repository,
        private LoggerInterface $logger,
        string $chatPattern = '/AIO/i'
    ) {
        $this->chatPattern = $chatPattern;
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

        $chat  = $message->getChat();
        $title = $chat->getTitle();
        if ($title === null || !preg_match($this->chatPattern, $title)) {
            // Only process chats matching the allowed pattern
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
