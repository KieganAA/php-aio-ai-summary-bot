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

    /** Всегда возвращает JSON под новую схему (через строгий pipeline/скелет) */
    private function generateSummary(int $chatId, int $now): array
    {
        $msgs = $this->repo->getMessagesForChat($chatId, $now);
        $chatTitle = (string)($this->repo->getChatTitle($chatId) ?? '');

        if (empty($msgs)) {
            $empty = [
                'chat_id' => $chatId,
                'date' => date('Y-m-d', $now),
                'verdict' => 'ok',
                'health_score' => 80,
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
            $this->logger->error('Summary hard fail', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            $summary = json_encode([
                'chat_id' => $chatId, 'date' => date('Y-m-d', $now), 'verdict' => 'ok', 'health_score' => 80,
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

    /** Отчёт по одному чату */
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
                } catch (Throwable) {
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

    /** Дайджест дня: шапка + все чаты ниже */
    public function runDigest(int $now): void
    {
        $this->logger->info('Running daily digest', ['day' => date('Y-m-d', $now)]);

        $reportsForLLM = [];    // сюда — JSON каждого чата для агрегатора
        $chatSections = [];    // сюда — для финального рендера (title + массив/JSON)

        foreach ($this->repo->listActiveChats($now) as $chatId) {
            $data = $this->generateSummary($chatId, $now);
            // generateSummary НИКОГДА не возвращает null
            $summary = $data['summary'];   // JSON string (executive_report)
            $chatTitle = (string)($data['title'] ?? '');

            $reportsForLLM[] = $summary;

            $chatSections[] = [
                'chat_id' => $chatId,
                'title' => $chatTitle !== '' ? $chatTitle : null,
                'report' => $summary,        // можно оставить строкой — рендерер сам декодирует
            ];

            // Сохраним в Notion каждый репорт по чату (по желанию)
            if ($this->notion !== null) {
                $titleN = 'Отчёт ' . date('Y-m-d', $now) . ' #' . $chatId;
                $this->notion->addReport($titleN, $this->toPlainText($summary));
            }
        }

        if (empty($reportsForLLM)) {
            // Нечего отправлять
            $this->logger->info('No active chat reports; digest skipped');
            return;
        }

        // 1) Собираем агрегированный дайджест (шапка)
        try {
            $digestJson = $this->deepseek->summarizeReports($reportsForLLM, date('Y-m-d', $now));
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate digest', ['error' => $e->getMessage()]);
            // Фолбэк: формально отрисуем только список чатов (без шапки)
            $digestJson = json_encode([
                'date' => date('Y-m-d', $now),
                'verdict' => 'ok',
                'scoreboard' => ['ok' => 0, 'warning' => 0, 'critical' => 0],
                'score_avg' => null,
                'top_attention' => [],
                'themes' => [],
                'risks' => [],
                'sla' => ['breaches' => [], 'at_risk' => []],
                'quality_flags' => ['digest_generation_failed'],
                'trimming_report' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // 2) Финальный текст: шапка + разворот по чатам
        $text = $this->renderer->renderDigestWithChats($digestJson, $chatSections);

        // 3) Отправляем в Telegram (с безопасным чанкингом)
        $this->telegram->sendMessage($this->summaryChatId, $text, 'MarkdownV2');

        // 4) Дублируем (при необходимости)
        if ($this->slack !== null) {
            $this->slack->sendMessage($this->toPlainText($text));
        }
        if ($this->notion !== null) {
            $this->notion->addReport('Digest ' . date('Y-m-d', $now), $this->toPlainText($digestJson));
        }

        $this->logger->info('Daily digest sent');
    }


    // --- helpers ---

    private function sendTelegramChunked(int $chatId, string $text, string $parseMode): void
    {
        if (mb_strlen($text, 'UTF-8') <= self::TG_MAX) {
            $this->telegram->sendMessage($chatId, $text, $parseMode);
            return;
        }

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
                    if ($chunk !== '') $buffer = $chunk;
                }
            }
        }
        $flush();
    }

    private function toPlainText(string $markdownV2): string
    {
        $text = $markdownV2;
        $text = str_replace(['\\|', '\\_', '\\*', '\\`', '\\[', '\\]', '\\(', '\\)', '\\#', '\\-'], ['|', '_', '*', '`', '[', ']', '(', ')', '#', '-'], $text);
        $text = str_replace(['*', '_', '`'], '', $text);
        return $text;
    }
}
