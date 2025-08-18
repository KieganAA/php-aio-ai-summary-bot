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
    private const TG_MAX = 4096;   // Telegram hard limit
    private const TG_BUDGET = 3900; // leave room for safety/escapes

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

        $msgCount = count($msgs);
        $users = array_values(array_filter(array_map(
            static fn($m) => (string)($m['from_user'] ?? ''),
            $msgs
        ), static fn($u) => $u !== ''));

        $userCount = count(array_unique($users));
        $topUsersCounted = array_count_values($users);
        arsort($topUsersCounted);
        $topUsers = array_slice(array_keys($topUsersCounted), 0, 3);

        $chatTitle = (string)($this->repo->getChatTitle($chatId) ?? '');

        // Pick generator (classic/executive) and force RU output with contextual hints
        $generator = $this->factory?->create($style)
            ?? (strtolower($style) === 'executive'
                ? new ExecutiveReportGenerator($this->deepseek)
                : new ClassicReportGenerator($this->deepseek));

        $signals = HealthSignalService::analyze($msgs, $now);

        try {
            $summary = $generator->summarize($transcript, [
                'chat_title' => $chatTitle,
                'chat_id'    => $chatId,
                'date'       => date('Y-m-d', $now),
                // enforce Russian output for all LLM prompts handled by generators
                'lang' => 'ru',
                // optional hint for tone
                'audience' => strtolower($style) === 'executive' ? 'executive' : 'team',
                'signals' => $signals,
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
            'signals' => $signals,
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

        $summary = $data['summary'];
        if ($style === 'executive') {
            // executive path returns JSON; convert to Telegram Markdown
            $summary = $this->formatExecutiveReport($summary);
        }

        $msgs      = $data['messages'];
        $chatTitle = $data['title'];
        $stats     = $data['stats'];

        $note = '';
        $lastMsgTs = (int)($msgs[count($msgs) - 1]['message_date'] ?? 0);

        if ($lastMsgTs > 0 && ($now - $lastMsgTs) < 3600) {
            $recent = array_slice($msgs, -5);
            $recentTranscript = TextUtils::buildCleanTranscript($recent);
            try {
                // Keep signature intact; ensure the Deepseek impl. itself is RU-biased
                $topicRaw = $this->deepseek->summarizeTopic($recentTranscript, $chatTitle, $chatId);
                $topic = TextUtils::escapeMarkdown($topicRaw);
                $note = "\n\n⚠️ *Сейчас обсуждают*: {$topic}";
            } catch (Throwable $e) {
                $this->logger->error('Failed to summarise active conversation', [
                    'chat_id' => $chatId,
                    'error'   => $e->getMessage(),
                ]);
                $note = "\n\n⚠️ *Активное обсуждение*";
            }
        }

        $dateLine = TextUtils::escapeMarkdown(date('Y-m-d', $now));
        $titleLine = $this->renderChatTitle($chatId, $chatTitle);

        $signals = $data['signals'] ?? null;
        $healthLine = '';
        if (is_array($signals)) {
            $map = ['ok' => '🟢', 'warning' => '🟠', 'critical' => '🔴'];
            $emoji = $map[$signals['status']] ?? '⚪️';
            $healthLine = "{$emoji} `Статус`: " . strtoupper($signals['status']) . " \\| `Оценка`: " . (int)$signals['score'];
            if (!empty($signals['reasons'])) {
                $why = array_slice($signals['reasons'], 0, 2);
                $healthLine .= "\n`Причины`: " . TextUtils::escapeMarkdown(implode('; ', $why));
            }
        }

        $header = "*Отчёт по чату* — {$titleLine}\n_{$dateLine}_"
            . ($healthLine ? "\n{$healthLine}" : '')
            . "\n\n";

        $statsLine = '`Сообщений`: ' . $stats['msg_count'] . ' \\| `Участников`: ' . $stats['user_count'];
        if (!empty($stats['top_users'])) {
            $usernames = array_map(fn($u) => $this->formatUsername((string)$u), $stats['top_users']);
            $statsLine .= "\n`Топ участников`: " . implode(', ', $usernames);
        }

        $reportText = $header . $statsLine . "\n\n" . $summary . $note;

        // Telegram: chunk-safe send
        $this->sendTelegramChunked($this->summaryChatId, $reportText, 'MarkdownV2');

        if ($this->slack !== null) {
            $this->slack->sendMessage($this->toPlainText($reportText));
        }

        if ($this->notion !== null) {
            $title = 'Отчёт ' . date('Y-m-d', $now) . ' #' . $chatId;
            $this->notion->addReport($title, $this->toPlainText($summary));
        }

        $this->logger->info('Daily report sent', ['chat_id' => $chatId]);
        $this->repo->markProcessed($chatId, $now);
    }

    /**
     * Convert a JSON report or digest into a Markdown formatted text suitable for Telegram.
     * Scalars as key-values, arrays as bullet lists. All values escaped for MarkdownV2.
     */
    private function formatJsonMessage(string $json, bool $stripMeta = false): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return TextUtils::escapeMarkdown($json);
        }

        if ($stripMeta) {
            unset($data['chat_id'], $data['date']);
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
                    $lines[] = '• ' . TextUtils::escapeMarkdown((string)$item);
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

    private function formatExecutiveReport(string $json): string
    {
        return $this->formatJsonMessage($json, true);
    }

    private function formatExecutiveDigest(string $json): string
    {
        return $this->formatJsonMessage($json);
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
                $u = (string)($m['from_user'] ?? '');
                if ($u !== '') {
                    $allUsers[$u] = true;
                }
            }

            if ($this->notion !== null) {
                $title = 'Отчёт ' . date('Y-m-d', $now) . ' #' . $chatId;
                $this->notion->addReport($title, $this->toPlainText($summary));
            }

            // NOTE: Do NOT mark processed in digest to avoid double processing.
            // Daily per-chat report handles markProcessed().
        }

        if (empty($reports)) {
            return;
        }

        try {
            // Keep signature; enforce RU inside Deepseek implementation itself
            $digest = $this->deepseek->summarizeReports($reports, date('Y-m-d', $now), $style);
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate digest', ['error' => $e->getMessage()]);
            return;
        }

        $dateLine = TextUtils::escapeMarkdown(date('Y-m-d', $now));
        $statsLine = '`Сообщений`: ' . $totalMessages . ' \\| `Участников`: ' . count($allUsers) . "\n\n";
        $header = "*Ежедневный дайджест*\n_{$dateLine}_\n\n" . $statsLine;

        $body = $style === 'executive'
            ? $this->formatExecutiveDigest($digest)
            : $digest;

        $text = $header . $body;

        $this->sendTelegramChunked($this->summaryChatId, $text, 'MarkdownV2');

        if ($this->slack !== null) {
            $this->slack->sendMessage($this->toPlainText($text));
        }

        if ($this->notion !== null) {
            $this->notion->addReport('Дайджест ' . date('Y-m-d', $now), $this->toPlainText($digest));
        }

        $this->logger->info('Daily digest sent');
    }

    // ---------- helpers ----------

    private function renderChatTitle(int $chatId, string $chatTitle): string
    {
        $title = trim($chatTitle);
        if ($title !== '') {
            return '*' . TextUtils::escapeMarkdown($title) . '*';
        }
        return '`#' . $chatId . '`';
    }

    private function formatUsername(string $raw): string
    {
        $u = ltrim(trim($raw), '@');
        if ($u === '') {
            return '';
        }
        return '@' . TextUtils::escapeMarkdown($u);
    }

    private function sendTelegramChunked(int $chatId, string $text, string $parseMode): void
    {
        // Fast path
        if (mb_strlen($text, 'UTF-8') <= self::TG_MAX) {
            $this->telegram->sendMessage($chatId, $text, $parseMode);
            return;
        }

        // Split by blank lines, then accumulate up to budget
        $parts = preg_split("/\n{2,}/u", $text) ?: [$text];
        $buffer = '';

        $flush = function () use (&$buffer, $chatId, $parseMode) {
            if ($buffer === '') return;
            // Telegram rejects empty/too short code blocks etc., keep it simple
            $this->telegram->sendMessage($chatId, rtrim($buffer), $parseMode);
            $buffer = '';
        };

        foreach ($parts as $p) {
            $candidate = $buffer === '' ? $p : ($buffer . "\n\n" . $p);
            if (mb_strlen($candidate, 'UTF-8') <= self::TG_BUDGET) {
                $buffer = $candidate;
            } else {
                $flush();
                if (mb_strlen($p, 'UTF-8') <= self::TG_BUDGET) {
                    $buffer = $p;
                } else {
                    // Extremely long paragraph: hard split by line
                    $lines = preg_split("/\n/u", $p) ?: [$p];
                    $chunk = '';
                    foreach ($lines as $line) {
                        $cand = $chunk === '' ? $line : ($chunk . "\n" . $line);
                        if (mb_strlen($cand, 'UTF-8') <= self::TG_BUDGET) {
                            $chunk = $cand;
                        } else {
                            $this->telegram->sendMessage($chatId, rtrim($chunk), $parseMode);
                            $chunk = $line;
                        }
                    }
                    if ($chunk !== '') {
                        $buffer = $chunk;
                    }
                }
            }
        }
        $flush();
    }

    private function toPlainText(string $markdownV2): string
    {
        // Minimal markdown cleanup for Slack/Notion: strip common formatting chars and escapes.
        $text = $markdownV2;
        $text = str_replace(['\\|', '\\_', '\\*', '\\`', '\\[', '\\]', '\\(', '\\)', '\\#', '\\-'], ['|', '_', '*', '`', '[', ']', '(', ')', '#', '-'], $text);
        $text = str_replace(['*', '_', '`'], '', $text);
        return $text;
    }
}
