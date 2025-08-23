<?php
declare(strict_types=1);

namespace Src\Service\Reports;

use Psr\Log\LoggerInterface;
use Src\Repository\MessageRepositoryInterface;
use Src\Service\Integrations\DeepseekService;
use Src\Service\Integrations\NotionService;
use Src\Service\Integrations\SlackService;
use Src\Service\LoggerService;
use Src\Service\Reports\Generators\ExecutiveReportGenerator;
use Src\Service\Reports\Renderers\TelegramRenderer;
use Src\Service\Telegram\TelegramService;
use Src\Util\TextUtils;
use Throwable;

class ReportService
{
    private const TG_MAX = 4096;
    private const TG_BUDGET = 3900;

    private LoggerInterface $logger;
    private TelegramRenderer $renderer;
    private ExecutiveReportGenerator $generator;

    public function __construct(
        private MessageRepositoryInterface $repo,
        private DeepseekService            $deepseek,
        private TelegramService            $telegram,
        private int                        $summaryChatId,
        private ?SlackService              $slack = null,
        private ?NotionService             $notion = null,
        ?ExecutiveReportGenerator $generator = null,
    ) {
        $this->logger = LoggerService::getLogger();
        $this->renderer = new TelegramRenderer();
        $this->generator = $generator ?? new ExecutiveReportGenerator($this->deepseek);
    }

    /** Никогда не возвращает null: всегда есть JSON под новую схему */
    private function generateSummary(int $chatId, int $now): array
    {
        $msgs = $this->repo->getMessagesForChat($chatId, $now);
        $chatTitle = (string)($this->repo->getChatTitle($chatId) ?? '');

        if (empty($msgs)) {
            $empty = [
                'chat_id' => $chatId,
                'date' => date('Y-m-d', $now),
                'verdict' => 'ok',
                'health_score' => 0,
                'client_mood' => 'нейтральный',
                'summary' => 'Сообщений за день не найдено.',
                'incidents' => [],
                'warnings' => [],
                'decisions' => [],
                'open_questions' => [],
                'sla' => ['breaches' => [], 'at_risk' => []],
                'timeline' => [],
                'notable_quotes' => [],
                'quality_flags' => ['no_messages'],
                'trimming_report' => [],
                'char_counts' => ['total' => 0],
                'tokens_estimate' => 0,
            ];
            return [
                'summary' => json_encode($empty, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                'messages' => [],
                'title' => $chatTitle,
                'stats' => ['msg_count' => 0, 'user_count' => 0, 'top_users' => []],
            ];
        }

        $msgCount = count($msgs);
        $users = array_values(array_filter(array_map(static fn($m) => (string)($m['from_user'] ?? ''), $msgs)));
        $userCount = count(array_unique($users));
        $topUsersCounted = array_count_values($users);
        arsort($topUsersCounted);
        $topUsers = array_slice(array_keys($topUsersCounted), 0, 3);

        try {
            $summary = $this->generator->summarizeWithMessages($msgs, [
                'chat_title' => $chatTitle,
                'chat_id'    => $chatId,
                'date'       => date('Y-m-d', $now),
                'lang'       => 'ru',
                'audience'   => 'executive',
            ]);
        } catch (Throwable $e) {
            // Из-за LLM-repair сюда фактически не попадём. На всякий случай — пустой каркас.
            $this->logger->error('Summary hard fail', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            $summary = json_encode([
                'chat_id' => $chatId, 'date' => date('Y-m-d', $now), 'verdict' => 'ok', 'health_score' => 0,
                'client_mood' => 'нейтральный', 'summary' => 'Данные недоступны.',
                'incidents' => [], 'warnings' => [], 'decisions' => [], 'open_questions' => [],
                'sla' => ['breaches' => [], 'at_risk' => []], 'timeline' => [], 'notable_quotes' => [],
                'quality_flags' => ['summary_hard_fail'], 'trimming_report' => [], 'char_counts' => ['total' => 0], 'tokens_estimate' => 0
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        return [
            'summary' => $summary,
            'messages' => $msgs,
            'title'    => $chatTitle,
            'stats' => ['msg_count' => $msgCount, 'user_count' => $userCount, 'top_users' => $topUsers],
        ];
    }

    public function runReportForChat(int $chatId, int $now): void
    {
        $data = $this->generateSummary($chatId, $now);

        $summary = $data['summary'];
        $msgs = $data['messages'];
        $chatTitle = $data['title'];

        $note = '';
        if (!empty($msgs)) {
            $lastMsgTs = (int)($msgs[count($msgs) - 1]['message_date'] ?? 0);
            if ($now - $lastMsgTs < 3600) {
                $recent = array_slice($msgs, -5);
                $recentTranscript = TextUtils::buildCleanTranscript($recent);
                try {
                    $topic = $this->deepseek->summarizeTopic($recentTranscript, $chatTitle, $chatId);
                    $topic = TextUtils::escapeMarkdown($topic);
                    $note = "\n\n⚠️ Сейчас обсуждают: {$topic}";
                } catch (Throwable $e) {
                    $note = "\n\n⚠️ Активное обсуждение";
                }
            }
        }

        $arr = json_decode($summary, true) ?: [];
        $reportText = $this->renderer->renderExecutiveChat($arr, $chatTitle) . $note;

        $this->sendTelegramChunked($this->summaryChatId, $reportText, 'MarkdownV2');
        if ($this->slack !== null) $this->slack->sendMessage(strip_tags($reportText));
        if ($this->notion !== null) $this->notion->addReport('Report ' . date('Y-m-d', $now) . ' #' . $chatId, strip_tags($summary));

        $this->repo->markProcessed($chatId, $now);
    }

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
            return $this->formatJsonMessage($json);
        }

        if (isset($data['overall_status']) || isset($data['warnings']) || isset($data['critical_chats'])) {
            return $this->formatJsonMessage($json);
        }

        if (isset($data['chat_summaries']) && is_array($data['chat_summaries'])) {
            $lines = [];

            foreach ($data['chat_summaries'] as $item) {
                if (is_string($item)) {
                    $decoded = json_decode($item, true);
                    if (is_array($decoded)) {
                        $item = $decoded;
                    }
                }

                if (!is_array($item)) {
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

                $lines[] = '';
            }

            while (!empty($lines) && trim(end($lines)) === '') {
                array_pop($lines);
            }
            return implode("\n", $lines);
        }

        return $this->formatJsonMessage($json);
    }

    public function runDigest(int $now): void
    {
        $this->logger->info('Running daily digest', [
            'day' => date('Y-m-d', $now),
        ]);

        $reports       = [];
        $totalMessages = 0;
        $allUsers      = [];

        foreach ($this->repo->listActiveChats($now) as $chatId) {
            $data = $this->generateSummary($chatId, $now);
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
        }

        if (empty($reports)) {
            return;
        }

        try {
            $digest = $this->deepseek->summarizeReports($reports, date('Y-m-d', $now));
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate digest', ['error' => $e->getMessage()]);
            return;
        }
        $text = $this->renderer->renderExecutiveDigest($digest);

        $this->telegram->sendMessage($this->summaryChatId, $text, 'MarkdownV2');
        if ($this->slack !== null) {
            $this->slack->sendMessage(strip_tags($text));
        }
        if ($this->notion !== null) {
            $this->notion->addReport('Digest ' . date('Y-m-d', $now), strip_tags($digest));
        }
        $this->logger->info('Daily digest sent');
    }

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
