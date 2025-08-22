<?php
declare(strict_types=1);

namespace Src\Service\Reports\Generators;

use RuntimeException;
use Src\Service\Integrations\DeepseekService;
use Throwable;
class ExecutiveReportGenerator
{
    private const REQUIRED_KEYS = [
        'chat_id', 'date', 'overall_status',
        'critical_chats', 'warnings', 'trending_topics',
        'sla_violations', 'client_mood', 'notable_quotes',
    ];

    public function __construct(private DeepseekService $deepseek)
    {
    }

    public function summarize(string $transcript, array $meta): string
    {
        $meta += [
            'lang' => 'ru',
            'audience' => 'executive',
        ];

        // 1) Ask LLM for strict JSON (RU values, EN keys)
        try {
            $json = $this->deepseek->executiveReport($transcript, $meta);
            $data = $this->decodeSafe($json);
            if ($data === null) {
                throw new RuntimeException('Invalid JSON from LLM');
            }
            $data = $this->coerceShape($data, $meta, $transcript);
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (Throwable) {
            // 2) Fallback: minimal JSON from heuristics + RU mood via LLM
            $status = $this->deriveStatus($transcript);
            $mood = $this->safeInferMood($transcript);

            $fallback = [
                'chat_id' => $meta['chat_id'] ?? 0,
                'date' => $meta['date'] ?? date('Y-m-d'),
                'overall_status' => $status,
                'critical_chats' => [],
                'warnings' => [],
                'trending_topics' => [],
                'sla_violations' => [],
                'client_mood' => $mood,
                'notable_quotes' => [],
            ];
            return json_encode($fallback, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }

    // ---------- helpers ----------

    private function decodeSafe(string $json): ?array
    {
        $data = json_decode($json, true);
        if (is_array($data)) {
            return $data;
        }
        // Try to salvage: extract first {...}
        if (preg_match('/\{.*\}/su', $json, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data)) {
                return $data;
            }
        }
        return null;
    }

    private function coerceShape(array $data, array $meta, string $transcript): array
    {
        // Ensure required keys exist, coerce types, clamp fields
        $data += [
            'chat_id' => $meta['chat_id'] ?? 0,
            'date' => $meta['date'] ?? date('Y-m-d'),
            'overall_status' => 'ok',
            'critical_chats' => [],
            'warnings' => [],
            'trending_topics' => [],
            'sla_violations' => [],
            'client_mood' => 'нейтральный',
            'notable_quotes' => [],
        ];

        $data['overall_status'] = $this->normalizeStatus((string)$data['overall_status']);
        $data['client_mood'] = $this->normalizeMood((string)$data['client_mood']);

        foreach (['critical_chats', 'warnings', 'trending_topics', 'sla_violations', 'notable_quotes'] as $k) {
            if (!isset($data[$k]) || !is_array($data[$k])) {
                $data[$k] = [];
            }
            // Keep arrays concise
            $data[$k] = array_values(array_filter(array_map(
                static fn($v) => is_string($v) ? trim($v) : (is_scalar($v) ? (string)$v : ''),
                $data[$k]
            ), static fn($s) => $s !== ''));
            $data[$k] = array_slice($data[$k], 0, 7);
        }

        // Last safety net for mood if empty
        if ($data['client_mood'] === '') {
            $data['client_mood'] = $this->safeInferMood($transcript);
        }

        // Ensure all required keys present
        foreach (self::REQUIRED_KEYS as $k) {
            if (!array_key_exists($k, $data)) {
                $data[$k] = in_array($k, ['critical_chats', 'warnings', 'trending_topics', 'sla_violations', 'notable_quotes'], true) ? [] : '';
            }
        }

        return $data;
    }

    private function normalizeStatus(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return match ($s) {
            'critical', 'критично', 'критический' => 'critical',
            'warning', 'предупреждение', 'предупрежд', 'желтый', 'жёлтый' => 'warning',
            default => 'ok',
        };
    }

    private function normalizeMood(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return match (true) {
            str_starts_with($s, 'поз') => 'позитивный',
            str_starts_with($s, 'ней') => 'нейтральный',
            str_starts_with($s, 'нег') || str_starts_with($s, 'плох') => 'негативный',
            default => 'нейтральный',
        };
    }

    private function deriveStatus(string $transcript): string
    {
        $t = mb_strtolower($transcript);
        if (str_contains($t, 'ошиб') || str_contains($t, 'критич') || str_contains($t, 'incident') || str_contains($t, 'outage')) {
            return 'critical';
        }
        if (str_contains($t, 'предупрежд') || str_contains($t, 'задерж') || str_contains($t, 'delay') || str_contains($t, 'risk')) {
            return 'warning';
        }
        return 'ok';
    }

    private function safeInferMood(string $transcript): string
    {
        try {
            $mood = $this->deepseek->inferMood($transcript); // returns RU string
            return $mood !== '' ? $mood : 'нейтральный';
        } catch (Throwable) {
            return 'нейтральный';
        }
    }
}
