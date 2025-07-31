<?php
declare(strict_types=1);

namespace Src\Commands\UserCommands;

use DeepSeek\DeepSeekClient;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Src\Config\Config;
use Src\Repository\MySQLMessageRepository;
use Src\Service\LoggerService;

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
        $repo = new MySQLMessageRepository();
        $todayTs = time();

        $msgs = $repo->getMessagesForChat($chatId, $todayTs);
        if (empty($msgs)) {
            return $this->replyToChat('No messages to summarize yet.');
        }

        $raw = '';
        foreach ($msgs as $m) {
            $t = date('H:i', $m['message_date']);
            $raw .= "[{$m['from_user']} @ {$t}] {$m['text']}\n";
        }

        $client = DeepSeekClient::build(config::get('DEEPSEEK_API_KEY'));
        $client->query(
            'Provide a concise summary focusing on tasks, issues, and decisions.',
            'system'
        );
        $client->query($raw, 'user');
        $summary = $client->run();
        $this->logger->info('Summary generated', ['chat_id' => $chatId]);

        $repo->markProcessed($chatId, $todayTs);
        $this->logger->info('Messages marked processed after summarize', ['chat_id' => $chatId]);
        $response = Request::sendMessage([
            'chat_id' => $chatId,
            'text' => "*Chat Summary:*\n{$summary}",
            'parse_mode' => 'Markdown',
        ]);
        $this->logger->info('Summary sent to chat', ['chat_id' => $chatId]);
        return $response;
    }
}
