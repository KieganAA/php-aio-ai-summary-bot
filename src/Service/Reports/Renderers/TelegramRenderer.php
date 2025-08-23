<?php
declare(strict_types=1);

namespace Src\Service\Reports\Renderers;

use Src\Util\TextUtils;

/**
 * TelegramRenderer
 * - EXECUTIVE REPORT (новая схема)
 * - DAILY DIGEST (шапка) + секции по каждому чату
 * - MarkdownV2-экранирование, без ETA.
 */
final class TelegramRenderer
{
    private const VERDICT_EMOJI = ['ok' => '🟢', 'warning' => '🟠', 'critical' => '🔴'];

    /* ===== EXECUTIVE PER-CHAT ===== */
    public function renderExecutiveChat(array $r, ?string $chatTitle = null): string
    {
        $r = $this->normalizeExecutiveChat($r);
        $lines = [];

        $emoji = self::VERDICT_EMOJI[$r['verdict']] ?? '⚪️';
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
        $hdr .= ' — `' . strtoupper((string)$r['verdict']) . '`';

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

        // Инциденты
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

        // Универсальные секции
        $sections = [
            'warnings' => 'Предупреждения',
            'decisions' => 'Решения',
            'open_questions' => 'Открытые вопросы',
            'timeline' => 'Важные события',
        ];
        foreach ($sections as $key => $title) {
            if (empty($r[$key]) || !is_array($r[$key])) continue;
            $vals = array_slice(array_values(array_filter($r[$key], 'is_string')), 0, $key === 'timeline' ? 7 : 3);
            if (!$vals) continue;
            $lines[] = '';
            $lines[] = '*' . TextUtils::escapeMarkdown($title) . '*';
            foreach ($vals as $v) $lines[] = '• ' . TextUtils::escapeMarkdown($v);
        }

        // Цитаты
        $quotes = [];
        if (!empty($r['notable_quotes']) && is_array($r['notable_quotes'])) {
            $quotes = array_slice(array_values(array_filter($r['notable_quotes'], 'is_string')), 0, 3);
        }
        // Фолбэк: если notable_quotes пуст, попробуем вытащить из evidence первых инцидентов
        if (!$quotes && !empty($r['incidents'])) {
            foreach ($r['incidents'] as $inc) {
                if (!empty($inc['evidence']) && is_array($inc['evidence'])) {
                    foreach ($inc['evidence'] as $ev) {
                        if (is_string($ev) && trim($ev) !== '') $quotes[] = $ev;
                        if (count($quotes) >= 3) break 2;
                    }
                }
            }
        }
        if ($quotes) {
            $lines[] = '';
            $lines[] = '*' . TextUtils::escapeMarkdown('Цитаты') . '*';
            foreach ($quotes as $q) $lines[] = '• ' . TextUtils::escapeMarkdown($q);
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

        // Футер-метрики
        $footer = $this->renderQualityFooter($r);
        if ($footer !== '') {
            $lines[] = '';
            $lines[] = $footer;
        }

        return implode("\n", $lines);
    }

    /* ===== DIGEST HEADER-ONLY (современная схема) ===== */
    public function renderExecutiveDigest(string $json): string
    {
        $data = json_decode($json, true);

        if (is_array($data) && (isset($data['verdict']) || isset($data['scoreboard']))) {
            $lines = [];
            $emoji = self::VERDICT_EMOJI[strtolower((string)($data['verdict'] ?? 'ok'))] ?? '⚪️';
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

            if (!empty($data['top_attention']) && is_array($data['top_attention'])) {
                $lines[] = '';
                $lines[] = '*Топ внимания*';
                foreach (array_slice($data['top_attention'], 0, 7) as $row) {
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

                    if ($sum !== '') $lines[] = '• ' . TextUtils::escapeMarkdown($sum);
                    foreach ($kps as $pt) $lines[] = '• ' . TextUtils::escapeMarkdown($pt);
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

            if (!empty($data['sla']) && is_array($data['sla'])) {
                $breaches = array_slice(array_values(array_filter((array)($data['sla']['breaches'] ?? []), 'is_string')), 0, 7);
                $atRisk = array_slice(array_values(array_filter((array)($data['sla']['at_risk'] ?? []), 'is_string')), 0, 7);
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

            $footer = $this->renderDigestQualityFooter($data);
            if ($footer !== '') {
                $lines[] = '';
                $lines[] = $footer;
            }

            return implode("\n", $lines);
        }

        return TextUtils::escapeMarkdown(is_string($json) ? $json : json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    /** ===== DIGEST + FULL PER-CHAT SECTIONS ===== */
    public function renderDigestWithChats(string $digestJson, array $executiveReports, array $titlesByChatId = []): string
    {
        $out = [];
        $out[] = $this->renderExecutiveDigest($digestJson);

        $reports = array_values(array_filter($executiveReports, 'is_array'));
        if (!$reports) {
            return implode("\n\n", $out);
        }

        $rank = ['critical' => 0, 'warning' => 1, 'ok' => 2];
        usort($reports, function (array $a, array $b) use ($rank): int {
            $va = strtolower((string)($a['verdict'] ?? 'ok'));
            $vb = strtolower((string)($b['verdict'] ?? 'ok'));
            $ra = $rank[$va] ?? 3;
            $rb = $rank[$vb] ?? 3;
            if ($ra === $rb) {
                $sa = (int)($a['health_score'] ?? 0);
                $sb = (int)($b['health_score'] ?? 0);
                return $sa <=> $sb;
            }
            return $ra <=> $rb;
        });

        $out[] = '';
        $out[] = '*По чатам*';
        foreach ($reports as $r) {
            $cid = $r['chat_id'] ?? null;
            $title = null;
            if ($cid !== null && isset($titlesByChatId[(string)$cid])) {
                $title = (string)$titlesByChatId[(string)$cid];
            } elseif ($cid !== null && isset($titlesByChatId[(int)$cid])) {
                $title = (string)$titlesByChatId[(int)$cid];
            }
            $out[] = '';
            $out[] = $this->renderExecutiveChat($r, $title);
        }

        return implode("\n", $out);
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

        if (!empty($r['quality_flags']) && is_array($r['quality_flags'])) {
            $flags = array_slice(array_values(array_filter($r['quality_flags'], 'is_string')), 0, 3);
            if ($flags) $parts[] = '⚑ ' . TextUtils::escapeMarkdown(implode(' · ', $flags));
        }

        if (!empty($r['trimming_report']) && is_array($r['trimming_report'])) {
            $tr = $r['trimming_report'];
            $im = (int)($tr['initial_messages'] ?? 0);
            $km = (int)($tr['kept_messages'] ?? 0);
            $kc = (int)($tr['kept_clusters'] ?? 0);
            $rules = array_slice(array_values(array_filter((array)($tr['primary_discard_rules'] ?? []), 'is_string')), 0, 3);
            $risks = array_slice(array_values(array_filter((array)($tr['potential_loss_risks'] ?? []), 'is_string')), 0, 2);

            $meta = "✂️ " . TextUtils::escapeMarkdown("kept {$km}/{$im}, clusters {$kc}");
            if ($rules) $meta .= " \\| " . TextUtils::escapeMarkdown('rules: ' . implode(', ', $rules));
            if ($risks) $meta .= " \\| " . TextUtils::escapeMarkdown('risks: ' . implode(', ', $risks));
            $parts[] = $meta;
        }

        if (!empty($r['char_counts']['total']) || !empty($r['tokens_estimate'])) {
            $cc = (int)($r['char_counts']['total'] ?? 0);
            $tk = (int)($r['tokens_estimate'] ?? 0);
            $parts[] = 'Σ `chars`:' . $cc . ' \\| `~tokens`:' . $tk;
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
            $meta = "✂️ " . TextUtils::escapeMarkdown("kept {$rk}/{$ri}");
            if ($rules) $meta .= " \\| " . TextUtils::escapeMarkdown('rules: ' . implode(', ', $rules));
            $parts[] = $meta;
        }

        return $parts ? implode("\n", $parts) : '';
    }
}
