<?php
declare(strict_types=1);

namespace Src\Service;

class ReportGeneratorFactory
{
    public function __construct(private DeepseekService $deepseek)
    {
    }

    public function create(string $style): ReportGeneratorInterface
    {
        return match (strtolower($style)) {
            'executive' => new ExecutiveReportGenerator(),
            default     => new ClassicReportGenerator($this->deepseek),
        };
    }
}
