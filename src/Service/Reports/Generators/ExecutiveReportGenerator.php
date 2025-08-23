<?php
declare(strict_types=1);

namespace Src\Service\Reports\Generators;

use Src\Service\Integrations\DeepseekService;

class ExecutiveReportGenerator
{
    public function __construct(private DeepseekService $deepseek)
    {
    }

    /**
     * НЕ используется по умолчанию, оставлено на случай прямой подачи текста.
     * Строгая схема, без фоллбеков — ошибки пробрасываются наверх.
     */
    public function summarize(string $transcript, array $meta): string
    {
        $meta += ['lang' => 'ru', 'audience' => 'executive'];
        return $this->deepseek->executiveReport($transcript, $meta);
    }

    /**
     * Основной путь: messages -> struct-chunks -> reducer -> executive.
     * Строгая схема, без фоллбеков — ошибки пробрасываются наверх.
     */
    public function summarizeWithMessages(array $messages, array $meta): string
    {
        return $this->deepseek->executiveFromMessages($messages, $meta);
    }
}
