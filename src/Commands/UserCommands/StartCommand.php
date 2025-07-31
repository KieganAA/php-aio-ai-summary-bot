<?php

namespace App\Bot\Commands;

use App\Services\DatabaseService;
use Exception;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

/**
 * Class StartCommand
 *
 * Handles the /start command.
 */
class StartCommand extends UserCommand
{
    protected $name = 'start';
    protected $description = 'Start command';
    protected $usage = '/start';
    protected $version = '1.0.0';

    /**
     * Execute the command.
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chat = $message->getChat();
        $chatId = $message->getChat()->getId();
        $user = $message->getFrom();

        $text = 'Hey';

        try {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (Exception $e) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]);
        }
    }
}