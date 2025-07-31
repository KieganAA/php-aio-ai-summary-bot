<?php
declare(strict_types=1);

namespace Src\Commands\UserCommands;

use DeepSeek\DeepSeekClient;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Src\Repository\MySQLMessageRepository;

class SummarizeCommand extends UserCommand
{
    protected $name = 'summarize';
    protected $description = 'On‑demand summary of today’s chat';
    protected $usage = '/summarize';
    protected $version = '1.0.0';

    public function execute(): ServerResponse
    {
        $chatId = $this->getMessage()->getChat()->getId();
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

        $client = DeepSeekClient::build(getenv('DEEPSEEK_API_KEY'));
        $client->query(
            'Provide a concise summary focusing on tasks, issues, and decisions.',
            'system'
        );
        $client->query($raw, 'user');
        $summary = $client->run();

        $repo->markProcessed($chatId, $todayTs);

        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => "*Chat Summary:*\n{$summary}",
            'parse_mode' => 'Markdown',
        ]);
    }
}
