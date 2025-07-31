<?php
declare(strict_types=1);

namespace Src\Service;

use Src\Repository\MessageRepositoryInterface;
use Src\Service\LoggerService;
use Psr\Log\LoggerInterface;
use Src\Util\TextUtils;

class ReportService
{
    private LoggerInterface $logger;

    public function __construct(
        private MessageRepositoryInterface $repo,
        private DeepseekService            $deepseek,
        private TelegramService            $telegram,
        private int                        $summaryChatId
    ) {
        $this->logger = LoggerService::getLogger();
    }

    public function runDailyReports(int $dayTs): void
    {
        $this->logger->info('Running daily reports', ['day' => date('Y-m-d', $dayTs)]);
        foreach ($this->repo->listActiveChats($dayTs) as $chatId) {
            $msgs = $this->repo->getMessagesForChat($chatId, $dayTs);
            if (empty($msgs)) {
                continue;
            }

            $transcript = \Src\Util\TextUtils::buildTranscript($msgs);

            $summary = $this->deepseek->summarize($transcript);
            $header = "*Report for chat* `{$chatId}`\n_" . date('Y-m-d', $dayTs) . "_\n\n";
            $this->telegram->sendMessage($this->summaryChatId, $header . $summary);
            $this->logger->info('Daily report sent', ['chat_id' => $chatId]);
            $this->repo->markProcessed($chatId, $dayTs);
        }
    }
}
