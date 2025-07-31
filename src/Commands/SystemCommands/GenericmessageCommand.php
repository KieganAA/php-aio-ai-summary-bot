<?php
declare(strict_types=1);

namespace Src\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Src\Repository\MySQLMessageRepository;

class GenericmessageCommand extends SystemCommand
{
    protected $name = 'genericmessage';
    protected $description = 'Handles every incoming message';
    protected $version = '1.0.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        if (!$message->hasText()) {
            // ignore non‑text
            return Request::emptyResponse();
        }

        (new MySQLMessageRepository())->add(
            $message->getChat()->getId(),
            [
                'message_id' => $message->getMessageId(),
                'date' => $message->getDate(),
                'from' => [
                    'username' => $message->getFrom()->getUsername() ?? '',
                    'id' => $message->getFrom()->getId(),
                ],
                'text' => $message->getText(),
            ]
        );

        return Request::emptyResponse();
    }
}