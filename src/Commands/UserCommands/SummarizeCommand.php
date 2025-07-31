<?php
declare(strict_types=1);

namespace Src\Commands\UserCommands;

use DeepSeek\DeepSeekClient;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\Keyboard;
use Src\Config\Config;
use Src\Repository\DbalMessageRepository;
use Src\Service\LoggerService;
use Src\Service\Database;
use Src\Util\TextUtils;

class SummarizeCommand extends UserCommand
{
    protected $name = 'summarize';
    protected $description = 'On‑demand summary of today’s chat';
    protected $usage = '/summarize';
    protected $version = '1.0.0';
    private $logger;

    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->logger = LoggerService::getLogger();
    }

    public function execute(): ServerResponse
    {
        $chatId = $this->getMessage()->getChat()->getId();
        $this->logger->info('Summarize command triggered', ['chat_id' => $chatId]);
        $conn = Database::getConnection($this->logger);
        $repo = new DbalMessageRepository($conn, $this->logger);

        $params = trim($this->getMessage()->getText(true));
        if ($params === '') {
            $buttons = [];
            foreach ($repo->listChats() as $chat) {
                $label = trim(($chat['title'] ?? '') . ' (' . $chat['id'] . ')');
                $buttons[] = [$label];
            }
            $keyboard = new Keyboard([
                'keyboard' => $buttons,
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ]);
            return $this->replyToChat('Send /summarize <chat_id> [YYYY-MM-DD]', [
                'reply_markup' => $keyboard,
            ]);
        }

        [$targetId, $dateStr] = array_pad(explode(' ', $params, 2), 2, '');
        $targetId = (int)$targetId;
        $dayTs = $dateStr !== '' ? strtotime($dateStr) : time();
        if ($dayTs === false) {
            return $this->replyToChat('Invalid date format, use YYYY-MM-DD');
        }

        $msgs = $repo->getMessagesForChat($targetId, $dayTs);
        if (empty($msgs)) {
            return $this->replyToChat('No messages to summarize yet.');
        }

        $raw = TextUtils::buildTranscript($msgs);
        $client = DeepSeekClient::build(Config::get('DEEPSEEK_API_KEY'));
        $client->query(
            'Provide a concise summary focusing on tasks, issues, and decisions.',
            'system'
        );
        $client->query($raw, 'user');
        $summary = $client->run();
        $this->logger->info('Summary generated', ['chat_id' => $targetId]);

        $repo->markProcessed($targetId, $dayTs);
        $this->logger->info('Messages marked processed after summarize', ['chat_id' => $targetId]);

        $response = Request::sendMessage([
            'chat_id' => $chatId,
            'text' => "*Chat Summary:*\n" . TextUtils::escapeMarkdown($summary),
            'parse_mode' => 'MarkdownV2',
        ]);
        $this->logger->info('Summary sent to chat', ['chat_id' => $chatId]);
        return $response;
    }
}
