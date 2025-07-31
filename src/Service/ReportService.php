<?php
declare(strict_types=1);

namespace Src\Service;

use Src\Repository\MessageRepositoryInterface;

class ReportService
{
    public function __construct(
        private MessageRepositoryInterface $repo,
        private DeepseekService            $deepseek,
        private TelegramService            $telegram,
        private int                        $summaryChatId
    )
    {
    }

    public function runDailyReports(int $dayTs): void
    {
        foreach ($this->repo->listActiveChats($dayTs) as $chatId) {
            $msgs = $this->repo->getMessagesForChat($chatId, $dayTs);
            if (empty($msgs)) {
                continue;
            }

            $transcript = '';
            foreach ($msgs as $m) {
                $t = date('H:i', $m['message_date']);
                $transcript .= "[{$m['from_user']} @ {$t}] {$m['text']}\n";
            }

            $summary = $this->deepseek->summarize($transcript);
            $header = "*Report for chat* `{$chatId}`\n_" . date('Y-m-d', $dayTs) . "_\n\n";
            $this->telegram->sendMessage($this->summaryChatId, $header . $summary);
            $this->repo->markProcessed($chatId, $dayTs);
        }
    }
}
