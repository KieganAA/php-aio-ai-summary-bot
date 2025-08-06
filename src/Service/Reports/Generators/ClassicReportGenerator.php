<?php
declare(strict_types=1);

namespace Src\Service\Reports\Generators;

use Src\Service\Integrations\DeepseekService;
use Src\Service\Reports\ReportGeneratorInterface;

class ClassicReportGenerator implements ReportGeneratorInterface
{
    public function __construct(private DeepseekService $deepseek)
    {
    }

    public function summarize(string $transcript, array $meta): string
    {
        $chatTitle = $meta['chat_title'] ?? '';
        $chatId    = $meta['chat_id'] ?? 0;
        $date      = $meta['date'] ?? date('Y-m-d');
        return $this->deepseek->summarize($transcript, $chatTitle, $chatId, $date);
    }

    public function getStyle(): string
    {
        return 'classic';
    }
}
