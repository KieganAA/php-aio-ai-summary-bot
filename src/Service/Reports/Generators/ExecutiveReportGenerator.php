<?php
declare(strict_types=1);

namespace Src\Service\Reports\Generators;

use Src\Service\Integrations\DeepseekService;

class ExecutiveReportGenerator
{
    public function __construct(private DeepseekService $deepseek)
    {
    }

    /** Основной путь — с массивом сообщений */
    public function summarizeWithMessages(array $messages, array $meta): string
    {
        return $this->deepseek->executiveFromMessages($messages, $meta);
    }

    /** Текстовый путь — редкий случай, сводим к messages для единого конвейера */
    public function summarize(string $transcript, array $meta): string
    {
        return $this->deepseek->executiveReport($transcript, $meta);
    }
}
