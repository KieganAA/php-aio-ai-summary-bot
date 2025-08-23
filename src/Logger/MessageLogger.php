<?php
// src/Logger/MessageLogger.php
declare(strict_types=1);

namespace Src\Logger;

use Longman\TelegramBot\Entities\Update;
use Psr\Log\LoggerInterface;
use Src\Entity\Message;
use Src\Repository\MessageRepositoryInterface;

/**
 * Handles incoming Telegram updates and persists relevant messages.
 * - Поддержка reply_to
 * - Корректный сбор текста (text | caption)
 * - Лёгкая маркировка вложений (photo/document/sticker/voice/video/audio)
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
            // Дополнительно можно обрабатывать editedMessage/channelPost при необходимости
            return;
        }

        $chat  = $message->getChat();
        $title = $chat->getTitle();
        if ($title === null || !preg_match($this->chatPattern, $title)) {
            // Only process chats matching the allowed pattern
            return;
        }

        // Основной текст: text -> caption -> пусто
        $text = $message->getText();
        if ($text === null || $text === '') {
            $text = $message->getCaption() ?? '';
        }

        // Сбор вложений (минимальный дескриптор)
        $attachments = [];
        if ($message->getPhoto()) {
            $attachments['photo'] = true;
        }
        if ($message->getDocument()) {
            $attachments['document'] = $message->getDocument()->getFileName();
        }
        if ($message->getSticker()) {
            $attachments['sticker'] = $message->getSticker()->getSetName() ?: true;
        }
        if ($message->getVoice()) {
            $attachments['voice'] = true;
        }
        if ($message->getVideo()) {
            $attachments['video'] = true;
        }
        if ($message->getAudio()) {
            $attachments['audio'] = true;
        }
        if ($message->getVideoNote()) {
            $attachments['video_note'] = true;
        }
        $attachmentsJson = !empty($attachments) ? json_encode($attachments, JSON_UNESCAPED_UNICODE) : null;

        // reply_to message id (если есть)
        $replyToId = $message->getReplyToMessage()?->getMessageId();

        $msg = new Message(
            $chat->getId(),
            $title,
            $message->getMessageId(),
            $message->getFrom()?->getUsername() ?? '',
            $message->getDate(),
            $text ?? '',
            $attachmentsJson,
            $replyToId
        );

        // Persist
        $this->repository->add($msg->chatId, [
            'message_id'  => $msg->messageId,
            'chat_title'  => $msg->chatTitle,
            'from'        => ['username' => $msg->fromUser],
            'date'        => $msg->messageDate,
            'text'        => $msg->text,
            'attachments' => $msg->attachments,
            'reply_to' => $msg->replyTo,
        ]);

        $this->logger->info('Message logged', [
            'chat' => $title,
            'id' => $msg->messageId,
            'rt' => $replyToId,
        ]);
    }
}
