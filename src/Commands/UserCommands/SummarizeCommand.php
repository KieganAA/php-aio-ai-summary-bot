<?php
declare(strict_types=1);

namespace Src\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Psr\Log\LoggerInterface;
use Src\Repository\DbalMessageRepository;
use Src\Service\Database;
use Src\Service\LoggerService;

class SummarizeCommand extends UserCommand
{
    protected $name = 'summarize';
    protected $description = 'On‑demand summary of chat';
    protected $usage = '/summarize';
    protected $version = '1.3.0';
    private LoggerInterface $logger;

    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->logger = LoggerService::getLogger();
    }

    public function execute(): ServerResponse
    {
        $chatId = $this->getMessage()->getChat()->getId();
        $user = $this->getMessage()->getFrom()->getUsername();
        $this->logger->info('Summarize command triggered', ['chat_id' => $chatId, 'user' => $user]);

        try {
            $conn = Database::getConnection($this->logger);
            $repo = new DbalMessageRepository($conn, $this->logger);

            $keyboard = new InlineKeyboard([]);
            foreach ($repo->listChats() as $chat) {
                $label = trim(($chat['title'] ?? '') . ' (' . $chat['id'] . ')');
                $keyboard->addRow(['text' => $label, 'callback_data' => 'sum_c_' . $chat['id']]);
            }

            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Select chat to summarize:',
                'reply_markup' => $keyboard,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to prepare summarize keyboard', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            return $this->replyToChat('Failed to list chats, please try again later.');
        }
    }
}

