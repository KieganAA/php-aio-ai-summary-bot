<?php
declare(strict_types=1);

namespace Src\Service;

use Src\Repository\MessageRepositoryInterface;
use Src\Service\LoggerService;
use Psr\Log\LoggerInterface;
use Src\Util\TextUtils;
use Src\Service\SlackService;
use Src\Service\NotionService;

class ReportService
{
    private LoggerInterface $logger;

    public function __construct(
        private MessageRepositoryInterface $repo,
        private DeepseekService            $deepseek,
        private TelegramService            $telegram,
        private int                        $summaryChatId,
        private ?SlackService              $slack = null,
        private ?NotionService             $notion = null,
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

            $transcript = TextUtils::cleanTranscript(TextUtils::buildTranscript($msgs));

            $chatTitle = $this->repo->getChatTitle($chatId);
            $summary = $this->deepseek->summarize(
                $transcript,
                $chatTitle,
                $chatId,
                date('Y-m-d', $dayTs)
            );
            $header = "*Report for chat* `{$chatId}`\n_" . date('Y-m-d', $dayTs) . "_\n\n";
            $reportText = $header . $summary;
            $this->telegram->sendMessage($this->summaryChatId, $reportText);
            if ($this->slack !== null) {
                $this->slack->sendMessage(strip_tags($reportText));
            }
            if ($this->notion !== null) {
                $title = 'Report ' . date('Y-m-d', $dayTs) . ' #' . $chatId;
                $this->notion->addReport($title, strip_tags($summary));
            }
            $this->logger->info('Daily report sent', ['chat_id' => $chatId]);
            $this->repo->markProcessed($chatId, $dayTs);
        }
    }
}
