<?php
declare(strict_types=1);

namespace Src\Service\Reports;

final class HealthSignalService
{
    /**
     * Analyze messages and return deterministic signals for exec overview.
     *
     * @param array $messages Each message should have: from_user, text, message_date (unix).
     * @return array{
     *   status: 'ok'|'warning'|'critical',
     *   score: int,                  // 0..100
     *   reasons: string[],           // RU one-liners
     *   metrics: array<string,mixed> // raw metrics for downstream use
     * }
     */
    public static function analyze(array $messages, int $now): array
    {
        $msgCount = count($messages);
        $reasons = [];
        $riskHits = 0;
        $warnHits = 0;
        $negHits = 0;
        $questions = 0;

        $lastTs = 0;
        $users = [];

        // Keyword buckets (RU+EN); short and conservative
        $critical = [
            'эскалация',
            'не работает', 'инцидент', 'лежим', 'упал',
            'возврат денег', 'возврат', 'пиздец',
            'рефанд', 'ужас', 'пиздец', 'айо лежит',
        ];
        $warning = [
            'задержки', 'дедлайн', 'риск', 'долго', 'ждем',
            'перенос', 'ожидание', 'sla', 'сла', 'нарушение',
        ];
        $negative = [
            'недоволен', 'плохо', 'разочарован', 'агресс', 'злю', 'злой',
        ];

        foreach ($messages as $m) {
            $text = mb_strtolower((string)($m['text'] ?? ''));
            $ts = (int)($m['message_date'] ?? 0);
            $user = (string)($m['from_user'] ?? '');
            if ($user !== '') {
                $users[$user] = true;
            }
            if ($ts > $lastTs) {
                $lastTs = $ts;
            }

            if (str_contains($text, '?')) {
                $questions++;
            }

            foreach ($critical as $kw) {
                if (mb_strpos($text, $kw) !== false) {
                    $riskHits += 2;
                }
            }
            foreach ($warning as $kw) {
                if (mb_strpos($text, $kw) !== false) {
                    $warnHits += 1;
                }
            }
            foreach ($negative as $kw) {
                if (mb_strpos($text, $kw) !== false) {
                    $negHits += 1;
                }
            }
        }

        $userCount = count($users);
        $ageSec = max(0, $now - ($lastTs ?: $now));
        $hours = max(1, (int)ceil(max(1, $msgCount) / 50)); // rough scaling window
        $msgsPerHr = $msgCount / max(1, $hours);

        // Heuristic score (start at 100 and subtract penalties)
        $score = 100;
        $score -= min(40, $riskHits * 8);
        $score -= min(25, $warnHits * 3);
        $score -= min(20, $negHits * 2);

        // If chat is “dead” (no msgs in 48h) — mild warning unless it’s a known quiet channel
        if ($ageSec > 48 * 3600 && $msgCount > 0) {
            $score -= 10;
            $reasons[] = 'Нет активности более 48 часов';
        }

        // If there are many questions but few answers (proxy): questions > 20% of messages
        if ($msgCount > 10 && ($questions / max(1, $msgCount)) > 0.2) {
            $score -= 10;
            $reasons[] = 'Много открытых вопросов в переписке';
        }

        // Crowd noise: very many participants spikes coordination risk
        if ($userCount >= 7) {
            $score -= 5;
            $reasons[] = 'Очень много участников — повышенный риск рассинхронизации';
        }

        $score = max(0, min(100, $score));

        // Status by worst signal
        $status = 'ok';
        if ($riskHits >= 1 || $score <= 55) {
            $status = 'critical';
        } elseif ($warnHits >= 1 || $score <= 75) {
            $status = 'warning';
        }

        // Reasons from keywords
        if ($riskHits >= 1) {
            $reasons[] = 'Обнаружены критические индикаторы (инциденты/эскалации)';
        }
        if ($warnHits >= 1) {
            $reasons[] = 'Обнаружены предупреждающие индикаторы (риск/задержки/SLA)';
        }
        if ($negHits >= 3) {
            $reasons[] = 'Негативная лексика встречается часто';
        }

        return [
            'status' => $status,
            'score' => $score,
            'reasons' => array_values(array_unique($reasons)),
            'metrics' => [
                'msg_count' => $msgCount,
                'user_count' => $userCount,
                'last_age_s' => $ageSec,
                'msgs_per_hr' => round($msgsPerHr, 2),
                'questions' => $questions,
                'risk_hits' => $riskHits,
                'warn_hits' => $warnHits,
                'neg_hits' => $negHits,
            ],
        ];
    }
}
