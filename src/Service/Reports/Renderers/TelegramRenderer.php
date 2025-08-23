<?php
declare(strict_types=1);

namespace Src\Service\Reports\Renderers;

use Src\Util\TextUtils;

/**
 * TelegramRenderer
 * - EXECUTIVE REPORT (единая схема)
 * - DAILY DIGEST (единая схема)
 * - Без выдумываний: выводим только то, что есть в JSON.
 * - MarkdownV2 безопасное экранирование, компактные секции, RU-надписи.
 */
final class TelegramRenderer
{
    private const VERDICT_EMOJI = ['ok' => '🟢', 'warning' => '🟠', 'critical' => '🔴'];

    /** EXECUTIVE JSON по одному чату → Telegram-формат */
    public function renderExecutiveChat(array $r, ?string $chatTitle = null): string
    {
        $r = $this->normalizeExecutiveChat($r);
        $lines = [];

        $verdict = strtolower((string)($r['verdict'] ?? 'ok'));
        $emoji = self::VERDICT_EMOJI[$verdict] ?? '⚪️';
        $chatId = $r['chat_id'] ?? null;
        $date = (string)($r['date'] ?? '');

        // Заголовок
        $hdr = "{$emoji} *Чат*";
        if ($chatTitle && trim($chatTitle) !== '') {
            $hdr .= ' ' . TextUtils::escapeMarkdown('«' . $chatTitle . '»');
        }
        if ($chatId !== null && $chatId !== '') {
            $hdr .= ' `#' . TextUtils::escapeMarkdown((string)$chatId) . '`';
        }
        $hdr .= ' — `' . strtoupper($verdict) . '`';

        if (isset($r['health_score']) && $r['health_score'] !== '' && $r['health_score'] !== null) {
            $hdr .= ' \\| `Оценка`: ' . (int)$r['health_score'];
        }
        if (!empty($r['client_mood'])) {
            $hdr .= ' \\| `Настроение`: ' . TextUtils::escapeMarkdown((string)$r['client_mood']);
        }
        if ($date !== '') {
            $hdr .= ' \\| `Дата`: ' . TextUtils::escapeMarkdown($date);
        }
        $lines[] = $hdr;

        // Кратко
        if (!empty($r['summary'])) {
            $lines[] = '';
            $lines[] = '*Кратко*: ' . TextUtils::escapeMarkdown((string)$r['summary']);
        }

        // Инциденты (топ-3)
        if (!empty($r['incidents']) && is_array($r['incidents'])) {
            $vals = array_values($r['incidents']);
            if ($vals) {
                $lines[] = '';
                $lines[] = '*Инциденты*';
                foreach (array_slice($vals, 0, 3) as $inc) {
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

                    if (!empty($inc['evidence']) && is_array($inc['evidence'])) {
                        foreach (array_slice(array_values(array_filter($inc['evidence'], 'is_string')), 0, 2) as $e) {
                            $row .= "\n  — " . TextUtils::escapeMarkdown($e);
                        }
                    }
                    $lines[] = $row;
                }
            }
        }

        // Универсальные списки
        $sections = [
            'warnings' => 'Предупреждения',
            'decisions' => 'Решения',
            'open_questions' => 'Открытые вопросы',
            'timeline' => 'Важные события',
            'notable_quotes' => 'Цитаты',
        ];
        foreach ($sections as $key => $ttl) {
            if (empty($r[$key]) || !is_array($r[$key])) continue;
            $vals = array_slice(array_values(array_filter($r[$key], 'is_string')), 0, $key === 'timeline' ? 5 : 3);
            if (!$vals) continue;
            $lines[] = '';
            $lines[] = '*' . TextUtils::escapeMarkdown($ttl) . '*';
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
                    $lines[] = '• Нарушения:';
                    foreach ($breaches as $b) $lines[] = '  • ' . TextUtils::escapeMarkdown($b);
                }
                if ($atRisk) {
                    $lines[] = '• Зона риска:';
                    foreach ($atRisk as $a) $lines[] = '  • ' . TextUtils::escapeMarkdown($a);
                }
            }
        }

        // Футер качества/тримминга
        $footer = $this->renderQualityFooter($r);
        if ($footer !== '') {
            $lines[] = '';
            $lines[] = $footer;
        }

        return implode("\n", $lines);
    }

    /** DAILY DIGEST JSON → Telegram-формат (единая схема) */
    public function renderExecutiveDigest(string $json): string
    {
        $d = json_decode($json, true);
        if (!is_array($d)) {
            // Фолбэк: покажем сырьём (экранировано)
            return TextUtils::escapeMarkdown(is_string($json) ? $json : json_encode($json, JSON_UNESCAPED_UNICODE));
        }

        // Современная сводка
        if (isset($d['verdict']) || isset($d['scoreboard'])) {
            $lines = [];
            $verdict = strtolower((string)($d['verdict'] ?? 'ok'));
            $emoji = self::VERDICT_EMOJI[$verdict] ?? '⚪️';
            $date = (string)($d['date'] ?? '');

            $hdr = "*Ежедневный дайджест*";
            if ($date !== '') $hdr .= "\n_" . TextUtils::escapeMarkdown($date) . "_";
            $lines[] = $hdr;

            $sb = (array)($d['scoreboard'] ?? []);
            $ok = (int)($sb['ok'] ?? 0);
            $wr = (int)($sb['warning'] ?? 0);
            $cr = (int)($sb['critical'] ?? 0);
            $avg = $d['score_avg'];
            $meta = "{$emoji} `Вердикт`: " . strtoupper($verdict)
                . " \\| `OK`: {$ok} \\| `WARN`: {$wr} \\| `CRIT`: {$cr}";
            if ($avg !== null) {
                $meta .= " \\| `Средняя оценка`: " . (int)$avg;
            }
            $lines[] = $meta;

            // Топ внимания
            if (!empty($d['top_attention']) && is_array($d['top_attention'])) {
                $lines[] = '';
                $lines[] = '*Топ внимания*';
                foreach (array_slice($d['top_attention'], 0, 7) as $row) {
                    if (!is_array($row)) continue;
                    $cid = $row['chat_id'] ?? '';
                    $ver = strtolower((string)($row['verdict'] ?? 'warning'));
                    $sc = $row['health_score'] ?? null;
                    $sum = (string)($row['summary'] ?? '');
                    $kps = array_slice(array_values(array_filter((array)($row['key_points'] ?? []), 'is_string')), 0, 3);

                    $badge = self::VERDICT_EMOJI[$ver] ?? '🟠';
                    $line = "{$badge} `#" . TextUtils::escapeMarkdown((string)$cid) . "` — `" . strtoupper($ver) . "`";
                    if (is_numeric($sc)) $line .= " \\| `Оценка`: " . (int)$sc;
                    $lines[] = $line;

                    if ($sum !== '') {
                        $lines[] = '• ' . TextUtils::escapeMarkdown($sum);
                    }
                    foreach ($kps as $pt) {
                        $lines[] = '• ' . TextUtils::escapeMarkdown($pt);
                    }
                    $lines[] = '';
                }
                while (!empty($lines) && trim(end($lines)) === '') array_pop($lines);
            }

            // Темы и риски (строго из отчётов)
            foreach (['themes' => 'Темы дня', 'risks' => 'Общие риски'] as $k => $ttl) {
                $vals = array_slice(array_values(array_filter((array)($d[$k] ?? []), 'is_string')), 0, 7);
                if (!$vals) continue;
                $lines[] = '';
                $lines[] = '*' . TextUtils::escapeMarkdown($ttl) . '*';
                foreach ($vals as $v) $lines[] = '• ' . TextUtils::escapeMarkdown($v);
            }

            // SLA (агрегировано)
            if (!empty($d['sla']) && is_array($d['sla'])) {
                $breaches = array_slice(array_values(array_filter((array)($d['sla']['breaches'] ?? []), 'is_string')), 0, 7);
                $atRisk = array_slice(array_values(array_filter((array)($d['sla']['at_risk'] ?? []), 'is_string')), 0, 7);
                if ($breaches || $atRisk) {
                    $lines[] = '';
                    $lines[] = '*SLA*';
                    if ($breaches) {
                        $lines[] = '• Нарушения:';
                        foreach ($breaches as $b) $lines[] = '  • ' . TextUtils::escapeMarkdown($b);
                    }
                    if ($atRisk) {
                        $lines[] = '• Зона риска:';
                        foreach ($atRisk as $a) $lines[] = '  • ' . TextUtils::escapeMarkdown($a);
                    }
                }
            }

            // Футер качества/тримминга
            $footer = $this->renderDigestQualityFooter($d);
            if ($footer !== '') {
                $lines[] = '';
                $lines[] = $footer;
            }

            return implode("\n", $lines);
        }

        // Легаси-форма (на всякий): безопасный вывод
        if (isset($d['chat_summaries']) && is_array($d['chat_summaries'])) {
            $out = [];
            $date = (string)($d['date'] ?? '');
            $hdr = "*Ежедневный дайджест*";
            if ($date !== '') $hdr .= "\n_" . TextUtils::escapeMarkdown($date) . "_";
            $out[] = $hdr;

            foreach ($d['chat_summaries'] as $item) {
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

        return TextUtils::escapeMarkdown(is_string($json) ? $json : json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    // ---------- helpers ----------

    private function normalizeExecutiveChat(array $r): array
    {
        if (!isset($r['verdict']) && isset($r['overall_status'])) {
            $r['verdict'] = strtolower((string)$r['overall_status']);
        }
        $r['verdict'] = in_array($r['verdict'] ?? 'ok', ['ok', 'warning', 'critical'], true) ? $r['verdict'] : 'ok';
        return $r;
    }

    private function renderQualityFooter(array $r): string
    {
        $parts = [];

        // Флаги качества (RU, без додумываний)
        if (!empty($r['quality_flags']) && is_array($r['quality_flags'])) {
            $flags = array_slice(array_values(array_filter($r['quality_flags'], 'is_string')), 0, 3);
            if ($flags) {
                $parts[] = '⚑ ' . TextUtils::escapeMarkdown(implode(' · ', $flags));
            }
        }

        // Trimming (по ключам отчёта)
        if (!empty($r['trimming_report']) && is_array($r['trimming_report'])) {
            $tr = $r['trimming_report'];
            $im = (int)($tr['initial_messages'] ?? 0);
            $km = (int)($tr['kept_messages'] ?? 0);
            $kc = (int)($tr['kept_clusters'] ?? 0);
            $rules = array_slice(array_values(array_filter((array)($tr['primary_discard_rules'] ?? []), 'is_string')), 0, 3);
            $risks = array_slice(array_values(array_filter((array)($tr['potential_loss_risks'] ?? []), 'is_string')), 0, 2);

            $meta = "✂️ " . TextUtils::escapeMarkdown("сохранено {$km}/{$im}, кластеров {$kc}");
            if ($rules) $meta .= " \\| " . TextUtils::escapeMarkdown('правила: ' . implode(', ', $rules));
            if ($risks) $meta .= " \\| " . TextUtils::escapeMarkdown('риски: ' . implode(', ', $risks));
            $parts[] = $meta;
        }

        // Тех.метрика
        if (!empty($r['char_counts']['total']) || !empty($r['tokens_estimate'])) {
            $cc = (int)($r['char_counts']['total'] ?? 0);
            $tk = (int)($r['tokens_estimate'] ?? 0);
            $parts[] = 'Σ `символы`:' . $cc . ' \\| `~токены`:' . $tk;
        }

        return $parts ? implode("\n", $parts) : '';
    }

    private function renderDigestQualityFooter(array $d): string
    {
        $parts = [];

        if (!empty($d['quality_flags']) && is_array($d['quality_flags'])) {
            $flags = array_slice(array_values(array_filter($d['quality_flags'], 'is_string')), 0, 3);
            if ($flags) $parts[] = '⚑ ' . TextUtils::escapeMarkdown(implode(' · ', $flags));
        }

        if (!empty($d['trimming_report']) && is_array($d['trimming_report'])) {
            $tr = $d['trimming_report'];
            $ri = (int)($tr['reports_in'] ?? 0);
            $rk = (int)($tr['reports_kept'] ?? 0);
            $rules = array_slice(array_values(array_filter((array)($tr['rules'] ?? []), 'is_string')), 0, 3);
            $meta = "✂️ " . TextUtils::escapeMarkdown("сохранено отчётов {$rk}/{$ri}");
            if ($rules) $meta .= " \\| " . TextUtils::escapeMarkdown('правила: ' . implode(', ', $rules));
            $parts[] = $meta;
        }

        return $parts ? implode("\n", $parts) : '';
    }
}
