<?php
declare(strict_types=1);

namespace Src\Service\Reports\Generators;

use Src\Service\Reports\ReportGeneratorInterface;

class ExecutiveReportGenerator implements ReportGeneratorInterface
{
    public function summarize(string $transcript, array $meta): string
    {
        $status = $this->deriveStatus($transcript);
        $data = [
            'chat_id'        => $meta['chat_id'] ?? 0,
            'date'           => $meta['date'] ?? date('Y-m-d'),
            'overall_status' => $status,
            'highlights'     => [],
            'risks'          => [],
        ];
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function deriveStatus(string $transcript): string
    {
        $t = mb_strtolower($transcript);
        if (str_contains($t, 'error') || str_contains($t, 'critical')) {
            return 'critical';
        }
        if (str_contains($t, 'warn') || str_contains($t, 'delay')) {
            return 'warning';
        }
        return 'ok';
    }

    public function getStyle(): string
    {
        return 'executive';
    }
}
