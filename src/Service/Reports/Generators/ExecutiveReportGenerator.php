<?php
declare(strict_types=1);

namespace Src\Service\Reports\Generators;

use Src\Service\Integrations\DeepseekService;
use Src\Service\Reports\ReportGeneratorInterface;

class ExecutiveReportGenerator implements ReportGeneratorInterface
{
    public function __construct(private DeepseekService $deepseek)
    {
    }

    public function summarize(string $transcript, array $meta): string
    {
        $status = $this->deriveStatus($transcript);
        $data   = [
            'chat_id'        => $meta['chat_id'] ?? 0,
            'date'           => $meta['date'] ?? date('Y-m-d'),
            'overall_status' => $status,
            'critical_chats' => [],
            'warnings'       => [],
            'trending_topics'=> [],
            'sla_violations' => [],
            'client_mood'    => $this->deriveMood($transcript),
            'notable_quotes' => [],
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function deriveStatus(string $transcript): string
    {
        $t = mb_strtolower($transcript);
        if ($this->containsAny($t, ['error', 'critical', 'ошиб', 'критич'])) {
            return 'critical';
        }
        if ($this->containsAny($t, ['warn', 'warning', 'delay', 'предупрежд', 'задерж'])) {
            return 'warning';
        }
        return 'ok';
    }

    private function deriveMood(string $transcript): string
    {
        try {
            $mood = $this->deepseek->inferMood($transcript);
            return $mood !== '' ? $mood : 'neutral';
        } catch (\Throwable) {
            return 'neutral';
        }
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function getStyle(): string
    {
        return 'executive';
    }
}
