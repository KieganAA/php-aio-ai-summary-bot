<?php
declare(strict_types=1);

namespace Src\Service\Reports;

use Src\Service\Integrations\DeepseekService;
use Src\Service\Reports\Generators\ClassicReportGenerator;
use Src\Service\Reports\Generators\ExecutiveReportGenerator;

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
