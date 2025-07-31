<?php
declare(strict_types=1);

namespace Src\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Src\Repository\MySQLMessageRepository;
use Src\Service\LoggerService;

class GenericmessageCommand extends SystemCommand
{
    protected $name = 'genericmessage';
    protected $description = 'Handles every incoming message';
    protected $version = '1.0.0';
    private $logger;

    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->logger = LoggerService::getLogger();
    }

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $text = $message->getText();

        if ($text === null) {
            // ignore non-text (photo captions, stickers, etc.)
            return Request::emptyResponse();
        }

        $this->logger->info('Incoming text message', [
            'chat_id' => $message->getChat()->getId(),
            'text' => $text,
        ]);

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