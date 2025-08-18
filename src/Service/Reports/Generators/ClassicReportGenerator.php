<?php
declare(strict_types=1);

namespace Src\Service\Reports\Generators;

use Src\Service\Integrations\DeepseekService;
use Src\Service\Reports\ReportGeneratorInterface;
use Throwable;

class ClassicReportGenerator implements ReportGeneratorInterface
{
    public function __construct(private DeepseekService $deepseek)
    {
    }

    public function summarize(string $transcript, array $meta): string
    {
        // Ensure RU + team-friendly style by default
        $meta += [
            'lang' => 'ru',
            'audience' => 'team', // affects tone/sections inside DeepseekService
        ];

        try {
            return $this->deepseek->summarizeClassic($transcript, $meta);
        } catch (Throwable) {
            // Fallback: ultra-short neutral stub (RU)
            $title = (string)($meta['chat_title'] ?? '');
            $date = (string)($meta['date'] ?? date('Y-m-d'));
            return "Краткий отчёт по чату «{$title}» за {$date} недоступен из-за ошибки генерации. Основная активность сохранена в логе.";
        }
    }

    public function getStyle(): string
    {
        return 'classic';
    }
}
