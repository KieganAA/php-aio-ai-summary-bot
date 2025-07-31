<?php
declare(strict_types=1);

namespace Src\Entity;

/**
 * Data transfer object for a Telegram message.
 */
class Message
{
    public function __construct(
        public int $chatId,
        public ?string $chatTitle,
        public int $messageId,
        public string $fromUser,
        public int $messageDate,
        public string $text,
        public ?string $attachments = null
    ) {}
}
