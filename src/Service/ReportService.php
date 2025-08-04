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

    public function runDailyReports(int $now): void
    {
        $this->logger->info('Running daily reports', ['day' => date('Y-m-d', $now)]);
        foreach ($this->repo->listActiveChats($now) as $chatId) {
            $msgs = $this->repo->getMessagesForChat($chatId, $now);
            if (empty($msgs)) {
                continue;
            }

            $transcript = TextUtils::cleanTranscript(TextUtils::buildTranscript($msgs));

            $chatTitle = $this->repo->getChatTitle($chatId);
            try {
                $summary = $this->deepseek->summarize(
                    $transcript,
                    $chatTitle,
                    $chatId,
                    date('Y-m-d', $now)
                );
            } catch (\Throwable $e) {
                $this->logger->error('Failed to generate summary', [
                    'chat_id' => $chatId,
                    'error'   => $e->getMessage(),
                ]);
                continue;
            }
            $note = '';
            $lastMsgTs = $msgs[count($msgs) - 1]['message_date'];
            if ($now - $lastMsgTs < 3600) {
                $recent = array_slice($msgs, -5);
                $recentTranscript = TextUtils::cleanTranscript(TextUtils::buildTranscript($recent));
                try {
                    $topic = $this->deepseek->summarizeTopic($recentTranscript, $chatTitle, $chatId);
                    $note = "\n\n⚠️ Active conversation about: {$topic}";
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to summarise active conversation', [
                        'chat_id' => $chatId,
                        'error'   => $e->getMessage(),
                    ]);
                    $note = "\n\n⚠️ Active conversation ongoing";
                }
            }
            $dateLine   = TextUtils::escapeMarkdown(date('Y-m-d', $now));
            $header     = "*Report for chat* `{$chatId}`\n_{$dateLine}_\n\n";
            $reportText = $header . $summary . $note;
            $this->telegram->sendMessage($this->summaryChatId, $reportText, 'MarkdownV2');
            if ($this->slack !== null) {
                $this->slack->sendMessage(strip_tags($reportText));
            }
            if ($this->notion !== null) {
                $title = 'Report ' . date('Y-m-d', $now) . ' #' . $chatId;
                $this->notion->addReport($title, strip_tags($summary));
            }
            $this->logger->info('Daily report sent', ['chat_id' => $chatId]);
            $this->repo->markProcessed($chatId, $now);
        }
    }
}
