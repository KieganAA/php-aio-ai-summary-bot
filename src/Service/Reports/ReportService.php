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
use Src\Service\Reports\Renderers\TelegramRenderer;
use Src\Service\Telegram\TelegramService;
use Src\Util\TextUtils;
use Throwable;

class ReportService
{
    private const TG_MAX = 4096;   // Telegram hard limit
    private const TG_BUDGET = 3900; // leave room for safety/escapes

    private LoggerInterface $logger;
    private TelegramRenderer $renderer;

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
        $this->renderer = new TelegramRenderer();
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

        $dateLine = TextUtils::escapeMarkdown(date('Y-m-d', $now));

        if (strtolower($style) === 'executive') {
            $arr = $this->decodeJsonToArray($summary);
            if (is_array($arr)) {
                $reportText = $this->renderer->renderExecutiveChat($arr, $chatTitle) . $note;
            } else {
                $reportText = $this->formatExecutiveReport($summary) . $note;
            }
        } else {
            $statsLine = '`Сообщений`: ' . $stats['msg_count'] . ' \\| `Участников`: ' . $stats['user_count'];
            if (!empty($stats['top_users'])) {
                $usernames = array_map(static fn($u) => '@' . TextUtils::escapeMarkdown($u), $stats['top_users']);
                $statsLine .= "\n`Топ`: " . implode(', ', $usernames);
            }
            $header = "*Отчёт по чату* " . TextUtils::escapeMarkdown("«{$chatTitle}»") . "\n_{$dateLine}_\n\n";
            $reportText = $header . $statsLine . "\n\n" . $summary . $note;
        }

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
        $data = json_decode($json, true);
        if (!is_array($data)) {
            // Фолбэк: на всякий случай распарсим как общий JSON → Markdown
            return $this->formatJsonMessage($json);
        }

        // Если это уже агрегированная сводка (а не список саммари по чатам),
        // отрисуем через общий форматер (он красивый для таких кейсов).
        if (isset($data['overall_status']) || isset($data['warnings']) || isset($data['critical_chats'])) {
            return $this->formatJsonMessage($json);
        }

        // Поддержка старого формата: {"date": "...", "chat_summaries": [ {...}, "...json..." ]}
        if (isset($data['chat_summaries']) && is_array($data['chat_summaries'])) {
            $lines = [];

            foreach ($data['chat_summaries'] as $item) {
                // Если пришла строка — попробуем распарсить
                if (is_string($item)) {
                    $decoded = json_decode($item, true);
                    if (is_array($decoded)) {
                        $item = $decoded;
                    }
                }

                if (!is_array($item)) {
                    // Совсем непредвидимый элемент — выводим как есть (экранируем)
                    $lines[] = '• ' . TextUtils::escapeMarkdown((string)$item);
                    $lines[] = '';
                    continue;
                }

                $chatId = $item['chat_id'] ?? null;
                $status = strtolower((string)($item['overall_status'] ?? 'ok'));
                $score = $item['health_score'] ?? null;
                $mood = $item['client_mood'] ?? null;

                $emojiMap = ['ok' => '🟢', 'warning' => '🟠', 'critical' => '🔴'];
                $emoji = $emojiMap[$status] ?? '⚪️';

                $header = "{$emoji} *Чат* ";
                if ($chatId !== null && $chatId !== '') {
                    $header .= '`#' . TextUtils::escapeMarkdown((string)$chatId) . '`';
                } else {
                    $header .= '`(без ID)`';
                }
                $header .= ' — `' . strtoupper($status) . '`';
                if (is_numeric($score)) {
                    $header .= ' \\| `Оценка`: ' . (int)$score;
                }
                if (is_string($mood) && $mood !== '') {
                    $header .= ' \\| `Настроение`: ' . TextUtils::escapeMarkdown($mood);
                }

                $lines[] = $header;

                // Короткие секции по топ-3 пункта
                $sections = [
                    'critical_chats' => 'Критично',
                    'warnings' => 'Предупреждения',
                    'sla_violations' => 'SLA',
                    'trending_topics' => 'Тренды',
                    'notable_quotes' => 'Цитаты',
                ];

                foreach ($sections as $key => $title) {
                    if (empty($item[$key]) || !is_array($item[$key])) {
                        continue;
                    }
                    $lines[] = '*' . TextUtils::escapeMarkdown($title) . '*';

                    $count = 0;
                    foreach ($item[$key] as $v) {
                        if ($count >= 3) {
                            break;
                        }
                        if (is_array($v)) {
                            $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }
                        $lines[] = '• ' . TextUtils::escapeMarkdown((string)$v);
                        $count++;
                    }
                }

                $lines[] = ''; // пустая строка между чатами
            }

            // Уберём возможные завершающие пустые строки
            while (!empty($lines) && trim(end($lines)) === '') {
                array_pop($lines);
            }
            return implode("\n", $lines);
        }

        // Непредвиденная форма — общий форматер нас выручит
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
            $digest = $this->deepseek->summarizeReports($reports, date('Y-m-d', $now), $style);
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate digest', ['error' => $e->getMessage()]);
            return;
        }

        if (strtolower($style) === 'executive') {
            $text = $this->renderer->renderExecutiveDigest($digest);
        } else {
            $dateLine = TextUtils::escapeMarkdown(date('Y-m-d', $now));
            $statsLine = '`Сообщений`: ' . $totalMessages . ' \\| `Участников`: ' . count($allUsers) . "\n\n";
            $header = "*Ежедневный дайджест*\n_{$dateLine}_\n\n" . $statsLine;
            $text = $header . $digest;
        }

        $this->telegram->sendMessage($this->summaryChatId, $text, 'MarkdownV2');
        if ($this->slack !== null) {
            $this->slack->sendMessage(strip_tags($text));
        }
        if ($this->notion !== null) {
            $this->notion->addReport('Digest ' . date('Y-m-d', $now), strip_tags($digest));
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

    private function decodeJsonToArray(?string $json): ?array
    {
        if (!is_string($json) || $json === '') return null;
        $data = json_decode($json, true);
        if (is_array($data)) return $data;
        if (preg_match('/\{.*\}/s', $json, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data)) return $data;
        }
        return null;
    }
}
