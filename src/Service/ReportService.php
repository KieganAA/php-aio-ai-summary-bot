<?php
declare(strict_types=1);

namespace Src\Service;

use Psr\Log\LoggerInterface;
use Src\Repository\MessageRepositoryInterface;
use Src\Util\TextUtils;

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

    private function generateSummary(int $chatId, int $now): ?array
    {
        $msgs = $this->repo->getMessagesForChat($chatId, $now);
        if (empty($msgs)) {
            return null;
        }

        $transcript = TextUtils::cleanTranscript(TextUtils::buildTranscript($msgs));
        $chatTitle  = $this->repo->getChatTitle($chatId);
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
            return null;
        }

        return ['summary' => $summary, 'messages' => $msgs, 'title' => $chatTitle];
    }

    public function runDailyReports(int $now): void
    {
        $this->logger->info('Running daily reports', ['day' => date('Y-m-d', $now)]);
        foreach ($this->repo->listActiveChats($now) as $chatId) {
            $this->runReportForChat($chatId, $now);
        }
    }

    public function runReportForChat(int $chatId, int $now): void
    {
        $data = $this->generateSummary($chatId, $now);
        if ($data === null) {
            return;
        }
        $summary   = $data['summary'];
        $msgs      = $data['messages'];
        $chatTitle = $data['title'];
        $note = '';
        $lastMsgTs = $msgs[count($msgs) - 1]['message_date'];
        if ($now - $lastMsgTs < 3600) {
            $recent = array_slice($msgs, -5);
            $recentTranscript = TextUtils::cleanTranscript(TextUtils::buildTranscript($recent));
            try {
                $topic = $this->deepseek->summarizeTopic($recentTranscript, $chatTitle, $chatId);
                $note = "\n\n⚠️ Сейчас обсуждают: {$topic}";
            } catch (\Throwable $e) {
                $this->logger->error('Failed to summarise active conversation', [
                    'chat_id' => $chatId,
                    'error'   => $e->getMessage(),
                ]);
                $note = "\n\n⚠️ Активное обсуждение";
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

    public function runDigest(int $now): void
    {
        $this->logger->info('Running daily digest', ['day' => date('Y-m-d', $now)]);
        $reports = [];
        foreach ($this->repo->listActiveChats($now) as $chatId) {
            $data = $this->generateSummary($chatId, $now);
            if ($data === null) {
                continue;
            }
            $summary = $data['summary'];
            $reports[] = $summary;
            if ($this->notion !== null) {
                $title = 'Report ' . date('Y-m-d', $now) . ' #' . $chatId;
                $this->notion->addReport($title, strip_tags($summary));
            }
            $this->repo->markProcessed($chatId, $now);
        }
        if (empty($reports)) {
            return;
        }
        try {
            $digest = $this->deepseek->summarizeReports($reports, date('Y-m-d', $now));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate digest', ['error' => $e->getMessage()]);
            return;
        }
        $dateLine = TextUtils::escapeMarkdown(date('Y-m-d', $now));
        $header   = "*Daily digest*\n_{$dateLine}_\n\n";
        $text     = $header . $digest;
        $this->telegram->sendMessage($this->summaryChatId, $text, 'MarkdownV2');
        if ($this->slack !== null) {
            $this->slack->sendMessage(strip_tags($text));
        }
        if ($this->notion !== null) {
            $this->notion->addReport('Digest ' . date('Y-m-d', $now), strip_tags($digest));
        }
        $this->logger->info('Daily digest sent');
    }
}

