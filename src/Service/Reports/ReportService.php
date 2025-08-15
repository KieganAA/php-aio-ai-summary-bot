<?php
declare(strict_types=1);

namespace Src\Service\Reports;

use Psr\Log\LoggerInterface;
use Src\Repository\MessageRepositoryInterface;
use Src\Service\Integrations\DeepseekService;
use Src\Service\Integrations\NotionService;
use Src\Service\Integrations\SlackService;
use Src\Service\LoggerService;
use Src\Service\Reports\Generators\ClassicReportGenerator;
use Src\Service\Reports\Generators\ExecutiveReportGenerator;
use Src\Service\Telegram\TelegramService;
use Src\Util\TextUtils;
use Throwable;

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
        private ?ReportGeneratorFactory    $factory = null,
    ) {
        $this->logger = LoggerService::getLogger();
    }

    private function generateSummary(int $chatId, int $now, string $style): ?array
    {
        $msgs = $this->repo->getMessagesForChat($chatId, $now);
        if (empty($msgs)) {
            return null;
        }

        $transcript = TextUtils::buildCleanTranscript($msgs);
        $msgCount   = count($msgs);
        $users      = array_column($msgs, 'from_user');
        $userCount  = count(array_unique($users));
        $topUsers   = array_count_values($users);
        arsort($topUsers);
        $topUsers   = array_slice(array_keys($topUsers), 0, 3);
        $chatTitle  = $this->repo->getChatTitle($chatId);
        $generator = $this->factory?->create($style)
            ?? (strtolower($style) === 'executive'
                ? new ExecutiveReportGenerator($this->deepseek)
                : new ClassicReportGenerator($this->deepseek));
        try {
            $summary = $generator->summarize($transcript, [
                'chat_title' => $chatTitle,
                'chat_id'    => $chatId,
                'date'       => date('Y-m-d', $now),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate summary', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }

        return [
            'summary' => $summary,
            'messages' => $msgs,
            'title'    => $chatTitle,
            'stats'    => [
                'msg_count'  => $msgCount,
                'user_count' => $userCount,
                'top_users'  => $topUsers,
            ],
        ];
    }

    public function runDailyReports(int $now, string $style = 'classic'): void
    {
        $this->logger->info('Running daily reports', [
            'day'   => date('Y-m-d', $now),
            'style' => $style,
        ]);
        foreach ($this->repo->listActiveChats($now) as $chatId) {
            $this->runReportForChat($chatId, $now, $style);
        }
    }

    public function runReportForChat(int $chatId, int $now, string $style = 'classic'): void
    {
        $data = $this->generateSummary($chatId, $now, $style);
        if ($data === null) {
            return;
        }
        $summary   = $data['summary'];
        if ($style === 'executive') {
            $summary = $this->formatExecutiveReport($summary);
        }
        $msgs      = $data['messages'];
        $chatTitle = $data['title'];
        $stats     = $data['stats'];
        $note = '';
        $lastMsgTs = $msgs[count($msgs) - 1]['message_date'];
        if ($now - $lastMsgTs < 3600) {
            $recent = array_slice($msgs, -5);
            $recentTranscript = TextUtils::buildCleanTranscript($recent);
            try {
                $topic = $this->deepseek->summarizeTopic($recentTranscript, $chatTitle, $chatId);
                $topic = TextUtils::escapeMarkdown($topic);
                $note = "\n\n⚠️ Сейчас обсуждают: {$topic}";
            } catch (Throwable $e) {
                $this->logger->error('Failed to summarise active conversation', [
                    'chat_id' => $chatId,
                    'error'   => $e->getMessage(),
                ]);
                $note = "\n\n⚠️ Активное обсуждение";
            }
        }
        $dateLine   = TextUtils::escapeMarkdown(date('Y-m-d', $now));
        $header     = "*Report for chat* `{$chatId}`\n_{$dateLine}_\n\n";
        $statsLine  = '`Messages`: ' . $stats['msg_count'] . ' \\| `Participants`: ' . $stats['user_count'];
        if (!empty($stats['top_users'])) {
            $usernames = array_map(static fn($u) => '@' . TextUtils::escapeMarkdown($u), $stats['top_users']);
            $statsLine .= "\n`Top`: " . implode(', ', $usernames);
        }
        $reportText = $header . $statsLine . "\n\n" . $summary . $note;
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

    /**
     * Convert executive report JSON into a Markdown formatted text.
     */
    private function formatExecutiveReport(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return TextUtils::escapeMarkdown($json);
        }

        unset($data['chat_id'], $data['date']);

        $lines = [];
        if (isset($data['overall_status'])) {
            $lines[] = '*Статус*: ' . TextUtils::escapeMarkdown((string) $data['overall_status']);
            unset($data['overall_status']);
        }

        foreach ($data as $section => $items) {
            if (is_array($items)) {
                if (empty($items)) {
                    continue;
                }
                $lines[] = '';
                $sectionName = str_replace('_', ' ', (string) $section);
                $lines[] = '*' . TextUtils::escapeMarkdown(ucfirst($sectionName)) . '*';
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $item = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    $lines[] = '  • ' . TextUtils::escapeMarkdown((string) $item);
                }
                continue;
            }
            if ($items === '' || $items === null) {
                continue;
            }
            $sectionName = str_replace('_', ' ', (string) $section);
            $lines[] = '';
            $lines[] = '*' . TextUtils::escapeMarkdown(ucfirst($sectionName)) . '*: ' . TextUtils::escapeMarkdown((string) $items);
        }

        return implode("\n", $lines);
    }

    /**
     * Convert executive digest JSON into a Markdown formatted text.
     */
    private function formatExecutiveDigest(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return TextUtils::escapeMarkdown($json);
        }

        $lines = [];
        if (isset($data['overall_status'])) {
            $lines[] = '*Статус*: ' . TextUtils::escapeMarkdown((string)$data['overall_status']);
            unset($data['overall_status']);
        }

        foreach ($data as $section => $items) {
            if (is_array($items)) {
                if (empty($items)) {
                    continue;
                }
                $lines[] = '';
                $sectionName = str_replace('_', ' ', (string)$section);
                $lines[] = '*' . TextUtils::escapeMarkdown(ucfirst($sectionName)) . '*';
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $item = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    $lines[] = '  • ' . TextUtils::escapeMarkdown((string)$item);
                }
                continue;
            }
            if ($items === '' || $items === null) {
                continue;
            }
            $sectionName = str_replace('_', ' ', (string)$section);
            $lines[] = '';
            $lines[] = '*' . TextUtils::escapeMarkdown(ucfirst($sectionName)) . '*: ' . TextUtils::escapeMarkdown((string)$items);
        }

        return implode("\n", $lines);
    }

    public function runDigest(int $now, string $style = 'executive'): void
    {
        $this->logger->info('Running daily digest', [
            'day'   => date('Y-m-d', $now),
            'style' => $style,
        ]);
        $reports       = [];
        $totalMessages = 0;
        $allUsers      = [];
        foreach ($this->repo->listActiveChats($now) as $chatId) {
            $data = $this->generateSummary($chatId, $now, $style);
            if ($data === null) {
                continue;
            }
            $summary = $data['summary'];
            $reports[] = $summary;
            foreach ($data['messages'] as $m) {
                $totalMessages++;
                $allUsers[$m['from_user']] = true;
            }
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
            $digest = $this->deepseek->summarizeReports($reports, date('Y-m-d', $now), $style);
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate digest', ['error' => $e->getMessage()]);
            return;
        }
        $dateLine = TextUtils::escapeMarkdown(date('Y-m-d', $now));
        $statsLine = '`Messages`: ' . $totalMessages . ' \\| `Participants`: ' . count($allUsers) . "\n\n";
        $header = "*Daily digest*\n_{$dateLine}_\n\n" . $statsLine;
        if ($style === 'executive') {
            $body = $this->formatExecutiveDigest($digest);
        } else {
            $body = $digest;
        }
        $text   = $header . $body;
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

