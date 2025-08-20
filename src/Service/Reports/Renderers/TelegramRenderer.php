<?php
declare(strict_types=1);

namespace Src\Service\Reports\Renderers;

use Src\Util\TextUtils;

final class TelegramRenderer
{
    private const VERDICT_EMOJI = ['ok' => '🟢', 'warning' => '🟠', 'critical' => '🔴'];

    /** EXECUTIVE JSON по одному чату → Telegram-формат */
    public function renderExecutiveChat(array $r, ?string $chatTitle = null): string
    {
        $r = $this->normalizeExecutiveChat($r);

        $lines = [];

        $emoji = self::VERDICT_EMOJI[$r['verdict']] ?? '⚪️';
        $chatId = $r['chat_id'] ?? null;
        $date = $r['date'] ?? '';

        // Заголовок
        $hdr = "{$emoji} *Чат*";
        if ($chatTitle && trim($chatTitle) !== '') {
            $hdr .= ' ' . TextUtils::escapeMarkdown('«' . $chatTitle . '»');
        }
        if ($chatId !== null && $chatId !== '') {
            $hdr .= ' `#' . TextUtils::escapeMarkdown((string)$chatId) . '`';
        }
        $hdr .= ' — `' . strtoupper($r['verdict']) . '`';

        if (isset($r['health_score']) && $r['health_score'] !== null && $r['health_score'] !== '') {
            $hdr .= ' \\| `Оценка`: ' . (int)$r['health_score'];
        }
        if (!empty($r['client_mood'])) {
            $hdr .= ' \\| `Настроение`: ' . TextUtils::escapeMarkdown((string)$r['client_mood']);
        }
        if (!empty($date)) {
            $hdr .= ' \\| `Дата`: ' . TextUtils::escapeMarkdown((string)$date);
        }
        $lines[] = $hdr;

        // Краткое summary
        if (!empty($r['summary'])) {
            $lines[] = '';
            $lines[] = '*Кратко*: ' . TextUtils::escapeMarkdown((string)$r['summary']);
        }

        // Инциденты (топ-3). ТОЛЬКО статус «resolved|unresolved», без ETA/шагов.
        if (!empty($r['incidents']) && is_array($r['incidents'])) {
            $lines[] = '';
            $lines[] = '*Инциденты*';
            $count = 0;
            foreach ($r['incidents'] as $inc) {
                if ($count >= 3) break;
                if (!is_array($inc)) continue;

                $title = (string)($inc['title'] ?? '');
                $impact = (string)($inc['impact'] ?? '');
                $status = strtolower((string)($inc['status'] ?? ''));
                $severity = (string)($inc['severity'] ?? '');

                $row = '• ' . TextUtils::escapeMarkdown($title);
                $meta = [];
                if ($severity !== '') $meta[] = 'sev:' . $severity;
                if ($status !== '') $meta[] = 'статус:' . ($status === 'resolved' ? 'решено' : 'не решено');
                if ($meta) $row .= ' (' . TextUtils::escapeMarkdown(implode(', ', $meta)) . ')';
                if ($impact !== '') $row .= "\n  " . TextUtils::escapeMarkdown($impact);

                // До 2 фактов/цитат
                if (!empty($inc['evidence']) && is_array($inc['evidence'])) {
                    $ev = array_slice(array_values(array_filter($inc['evidence'], 'is_string')), 0, 2);
                    foreach ($ev as $e) {
                        $row .= "\n  — " . TextUtils::escapeMarkdown($e);
                    }
                }

                $lines[] = $row;
                $count++;
            }
        }

        // Универсальные секции (без next_steps)
        $sections = [
            'warnings' => 'Предупреждения',
            'decisions' => 'Решения',
            'open_questions' => 'Открытые вопросы',
            'timeline' => 'Важные события',
            'notable_quotes' => 'Цитаты',
        ];
        foreach ($sections as $key => $title) {
            if (empty($r[$key]) || !is_array($r[$key])) continue;
            $vals = array_slice(array_values(array_filter($r[$key], 'is_string')), 0, 3);
            if (!$vals) continue;
            $lines[] = '';
            $lines[] = '*' . TextUtils::escapeMarkdown($title) . '*';
            foreach ($vals as $v) $lines[] = '• ' . TextUtils::escapeMarkdown($v);
        }

        // SLA
        if (!empty($r['sla']) && is_array($r['sla'])) {
            $breaches = array_slice(array_values(array_filter((array)($r['sla']['breaches'] ?? []), 'is_string')), 0, 5);
            $atRisk = array_slice(array_values(array_filter((array)($r['sla']['at_risk'] ?? []), 'is_string')), 0, 5);
            if ($breaches || $atRisk) {
                $lines[] = '';
                $lines[] = '*SLA*';
                if ($breaches) {
                    $lines[] = '• ' . TextUtils::escapeMarkdown('Нарушения:');
                    foreach ($breaches as $b) $lines[] = '  • ' . TextUtils::escapeMarkdown($b);
                }
                if ($atRisk) {
                    $lines[] = '• ' . TextUtils::escapeMarkdown('Зона риска:');
                    foreach ($atRisk as $a) $lines[] = '  • ' . TextUtils::escapeMarkdown($a);
                }
            }
        }

        return implode("\n", $lines);
    }

    /** EXECUTIVE DIGEST JSON или legacy → Telegram-формат */
    public function renderExecutiveDigest(string $json): string
    {
        $data = json_decode($json, true);

        // Современная сводка (вершина)
        if (is_array($data) && (isset($data['verdict']) || isset($data['scoreboard']))) {
            $lines = [];
            $emoji = self::VERDICT_EMOJI[strtolower((string)$data['verdict'] ?? 'ok')] ?? '⚪️';
            $date = (string)($data['date'] ?? '');
            $hdr = "*Ежедневный дайджест*";
            if ($date !== '') $hdr .= "\n_" . TextUtils::escapeMarkdown($date) . "_";
            $lines[] = $hdr;

            $sb = (array)($data['scoreboard'] ?? []);
            $ok = (int)($sb['ok'] ?? 0);
            $wr = (int)($sb['warning'] ?? 0);
            $cr = (int)($sb['critical'] ?? 0);
            $avg = isset($data['score_avg']) ? (int)$data['score_avg'] : null;

            $meta = "{$emoji} `Вердикт`: " . strtoupper((string)($data['verdict'] ?? 'ok'))
                . " \\| `OK`: {$ok} \\| `WARN`: {$wr} \\| `CRIT`: {$cr}";
            if ($avg !== null) $meta .= " \\| `Средняя оценка`: {$avg}";
            $lines[] = $meta;

            // Топ внимания: без "следующих шагов"
            if (!empty($data['top_attention']) && is_array($data['top_attention'])) {
                $lines[] = '';
                $lines[] = '*Топ внимания*';
                foreach (array_slice($data['top_attention'], 0, 5) as $row) {
                    if (!is_array($row)) continue;
                    $cid = $row['chat_id'] ?? '';
                    $ver = strtolower((string)($row['verdict'] ?? 'warning'));
                    $sc = $row['health_score'] ?? null;
                    $summary = (string)($row['summary'] ?? '');
                    $keyPts = array_slice(array_values(array_filter((array)($row['key_points'] ?? []), 'is_string')), 0, 3);

                    $badge = self::VERDICT_EMOJI[$ver] ?? '🟠';
                    $line = "{$badge} `#" . TextUtils::escapeMarkdown((string)$cid) . "` — `" . strtoupper($ver) . "`";
                    if (is_numeric($sc)) $line .= " \\| `Оценка`: " . (int)$sc;
                    $lines[] = $line;

                    if ($summary !== '') {
                        $lines[] = '• ' . TextUtils::escapeMarkdown($summary);
                    }
                    foreach ($keyPts as $pt) {
                        $lines[] = '• ' . TextUtils::escapeMarkdown($pt);
                    }
                    $lines[] = '';
                }
                while (!empty($lines) && trim(end($lines)) === '') array_pop($lines);
            }

            foreach (['themes' => 'Темы дня', 'risks' => 'Общие риски'] as $k => $ttl) {
                $vals = array_slice(array_values(array_filter((array)($data[$k] ?? []), 'is_string')), 0, 7);
                if (!$vals) continue;
                $lines[] = '';
                $lines[] = '*' . TextUtils::escapeMarkdown($ttl) . '*';
                foreach ($vals as $v) $lines[] = '• ' . TextUtils::escapeMarkdown($v);
            }

            return implode("\n", $lines);
        }

        // Legacy: {"date":"...","chat_summaries":[ ...json|string... ]}
        if (is_array($data) && isset($data['chat_summaries']) && is_array($data['chat_summaries'])) {
            $out = [];
            $date = (string)($data['date'] ?? '');
            $hdr = "*Ежедневный дайджест*";
            if ($date !== '') $hdr .= "\n_" . TextUtils::escapeMarkdown($date) . "_";
            $out[] = $hdr;

            foreach ($data['chat_summaries'] as $item) {
                if (is_string($item)) {
                    $decoded = json_decode($item, true);
                    if (is_array($decoded)) $item = $decoded;
                }
                if (is_array($item)) {
                    $out[] = '';
                    $out[] = $this->renderExecutiveChat($item);
                } elseif ($item !== null) {
                    $out[] = '• ' . TextUtils::escapeMarkdown((string)$item);
                }
            }

            return implode("\n", $out);
        }

        // Фолбэк
        return TextUtils::escapeMarkdown(is_string($json) ? $json : json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    /** CLASSIC JSON (агрегат) → Telegram-формат */
    public function renderClassic(array $c, string $chatTitle, int $chatId, string $date): string
    {
        $lines = [];
        $titleWithId = TextUtils::escapeMarkdown("{$chatTitle} (ID {$chatId})");
        $dateLine = TextUtils::escapeMarkdown($date);
        $lines[] = "*{$titleWithId}* — {$dateLine}";

        $sections = [
            'highlights' => 'Итоги дня',
            'issues' => 'Проблемы',
            'decisions' => 'Решения',
            'actions' => 'Задачи',
            'blockers' => 'Блокеры',
            'questions' => 'Открытые вопросы',
            'timeline' => 'События',
            'participants' => 'Участники',
        ];

        foreach ($sections as $k => $ttl) {
            $vals = $c[$k] ?? [];
            if (is_string($vals)) $vals = [$vals];
            if (!is_array($vals)) $vals = [];
            $limit = $k === 'participants' ? 20 : 7;
            $vals = array_slice(array_values(array_filter($vals, 'is_string')), 0, $limit);
            if (!$vals) continue;

            $lines[] = '*' . TextUtils::escapeMarkdown($ttl) . '*';
            foreach ($vals as $v) $lines[] = '• ' . TextUtils::escapeMarkdown($v);
        }

        return implode("\n", $lines);
    }

    private function normalizeExecutiveChat(array $r): array
    {
        if (!isset($r['verdict']) && isset($r['overall_status'])) {
            $r['verdict'] = strtolower((string)$r['overall_status']);
        }
        $r['verdict'] = in_array($r['verdict'] ?? 'ok', ['ok', 'warning', 'critical'], true) ? $r['verdict'] : 'ok';
        return $r;
    }
}
