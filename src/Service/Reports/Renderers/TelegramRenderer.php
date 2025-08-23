<?php
declare(strict_types=1);

namespace Src\Service\Reports\Renderers;

use Src\Util\TextUtils;

final class TelegramRenderer
{
    private const VERDICT_EMOJI = ['ok' => '🟢', 'warning' => '🟠', 'critical' => '🔴'];

    public function renderExecutiveChat(array $r, ?string $chatTitle = null): string
    {
        $r = $this->normalizeExecutiveChat($r);
        $lines = [];

        $emoji = self::VERDICT_EMOJI[$r['verdict']] ?? '⚪️';
        $chatId = $r['chat_id'] ?? null;
        $date = (string)($r['date'] ?? '');

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
        if (!empty($r['trend']['health_delta'])) {
            $d = (int)$r['trend']['health_delta'];
            $hdr .= ' ' . ($d >= 0 ? '(▲+' . $d . ')' : '(▼' . $d . ')');
        }
        $lines[] = $hdr;

        if (!empty($r['summary'])) {
            $lines[] = '';
            $lines[] = '*Кратко*: ' . TextUtils::escapeMarkdown((string)$r['summary']);
        }

        // Инциденты (с ссылками на message_id)
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
                $mid = $inc['message_id'] ?? null;

                $row = '• ' . TextUtils::escapeMarkdown($title);
                $meta = [];
                if ($severity !== '') $meta[] = 'sev:' . $severity;
                if ($status !== '') $meta[] = 'статус:' . ($status === 'resolved' ? 'решено' : 'не решено');
                if ($meta) $row .= ' (' . TextUtils::escapeMarkdown(implode(', ', $meta)) . ')';

                $link = $this->tgLink($chatId, $mid);
                if ($link !== null) $row .= ' [↗](' . $link . ')';

                if ($impact !== '') $row .= "\n  " . TextUtils::escapeMarkdown($impact);

                $refs = [];
                if (!empty($inc['evidence_refs']) && is_array($inc['evidence_refs'])) {
                    foreach (array_slice($inc['evidence_refs'], 0, 2) as $ref) {
                        if (!is_array($ref)) continue;
                        $q = (string)($ref['quote'] ?? '');
                        $m = $ref['message_id'] ?? null;
                        if ($q !== '') {
                            $refLine = '  — ' . TextUtils::escapeMarkdown($q);
                            $l = $this->tgLink($chatId, $m);
                            if ($l !== null) $refLine .= ' [↗](' . $l . ')';
                            $refs[] = $refLine;
                        }
                    }
                } elseif (!empty($inc['evidence']) && is_array($inc['evidence'])) {
                    foreach (array_slice($inc['evidence'], 0, 2) as $q) {
                        if (!is_string($q) || $q === '') continue;
                        $refs[] = '  — ' . TextUtils::escapeMarkdown($q);
                    }
                }
                if ($refs) $row .= "\n" . implode("\n", $refs);

                $lines[] = $row;
                $count++;
            }
        }

        // Предупреждения / Решения / Открытые вопросы / Важные события / Цитаты — через *_meta
        $this->renderListWithLinks($lines, $r, $chatId, 'warnings', 'Предупреждения', 3);
        $this->renderListWithLinks($lines, $r, $chatId, 'decisions', 'Решения', 3);
        $this->renderListWithLinks($lines, $r, $chatId, 'open_questions', 'Открытые вопросы', 3);
        $this->renderListWithLinks($lines, $r, $chatId, 'timeline', 'Важные события', 5);

        // Цитаты отдельно — поддерживаем notable_quotes_meta
        if (!empty($r['notable_quotes_meta']) && is_array($r['notable_quotes_meta'])) {
            $vals = array_slice($r['notable_quotes_meta'], 0, 3);
            if ($vals) {
                $lines[] = '';
                $lines[] = '*Цитаты*';
                foreach ($vals as $row) {
                    if (!is_array($row)) continue;
                    $q = (string)($row['quote'] ?? '');
                    $mid = $row['message_id'] ?? null;
                    $line = '• ' . TextUtils::escapeMarkdown($q);
                    $l = $this->tgLink($chatId, $mid);
                    if ($l !== null) $line .= ' [↗](' . $l . ')';
                    $lines[] = $line;
                }
            }
        } elseif (!empty($r['notable_quotes']) && is_array($r['notable_quotes'])) {
            $vals = array_slice(array_values(array_filter($r['notable_quotes'], 'is_string')), 0, 3);
            if ($vals) {
                $lines[] = '';
                $lines[] = '*Цитаты*';
                foreach ($vals as $v) $lines[] = '• ' . TextUtils::escapeMarkdown($v);
            }
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

        $footer = $this->renderQualityFooter($r);
        if ($footer !== '') {
            $lines[] = '';
            $lines[] = $footer;
        }

        return implode("\n", $lines);
    }

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

        return TextUtils::escapeMarkdown(is_string($json) ? $json : json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    private function renderListWithLinks(array &$lines, array $r, $chatId, string $baseKey, string $title, int $limit): void
    {
        $metaKey = $baseKey . '_meta';
        if (!empty($r[$metaKey]) && is_array($r[$metaKey])) {
            $vals = array_slice($r[$metaKey], 0, $limit);
            if ($vals) {
                $lines[] = '';
                $lines[] = '*' . TextUtils::escapeMarkdown($title) . '*';
                foreach ($vals as $row) {
                    if (!is_array($row)) continue;
                    $text = (string)($row['text'] ?? $row['quote'] ?? '');
                    $mid = $row['message_id'] ?? null;
                    if ($text === '') continue;
                    $line = '• ' . TextUtils::escapeMarkdown($text);
                    $l = $this->tgLink($r['chat_id'] ?? $chatId, $mid);
                    if ($l !== null) $line .= ' [↗](' . $l . ')';
                    $lines[] = $line;
                }
                return;
            }
        }
        if (!empty($r[$baseKey]) && is_array($r[$baseKey])) {
            $vals = array_slice(array_values(array_filter($r[$baseKey], 'is_string')), 0, $limit);
            if ($vals) {
                $lines[] = '';
                $lines[] = '*' . TextUtils::escapeMarkdown($title) . '*';
                foreach ($vals as $v) $lines[] = '• ' . TextUtils::escapeMarkdown($v);
            }
        }
    }

    private function normalizeExecutiveChat(array $r): array
    {
        if (!isset($r['verdict']) && isset($r['overall_status'])) {
            $r['verdict'] = strtolower((string)$r['overall_status']);
        }
        $r['verdict'] = in_array($r['verdict'] ?? 'ok', ['ok', 'warning', 'critical'], true) ? $r['verdict'] : 'ok';
        return $r;
    }

    private function tgLink($chatId, $messageId): ?string
    {
        if (!$chatId || !$messageId) return null;
        $cid = (int)$chatId;
        // private/supergroup: -100xxxxxxxxxx → t.me/c/<abs(id)-1000000000000>/<msg_id>
        if ($cid < 0) {
            $internal = abs($cid) - 1000000000000;
            if ($internal <= 0) return null;
            return "https://t.me/c/{$internal}/{$messageId}";
        }
        // публичный чат по username тут не известен — пропускаем
        return null;
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
