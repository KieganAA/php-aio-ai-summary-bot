<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Src\Service\DeepseekService;

class DeepseekServiceTest extends TestCase
{
    public function testJsonToMarkdown(): void
    {
        $service = new DeepseekService('key');
        $data = [
            'participants' => ['Алиса — разработчик'],
            'topics' => ['Обсуждали релиз'],
            'issues' => ['Сервер лежал'],
            'decisions' => ['Исправить баг'],
        ];

        $ref = new ReflectionClass(DeepseekService::class);
        $method = $ref->getMethod('jsonToMarkdown');
        $method->setAccessible(true);

        $md = $method->invoke($service, $data, 'Chat', 1, '2025-01-01');

        $this->assertStringContainsString('# Сводка чата', $md);
        $this->assertStringContainsString('1. 👥  Участники', $md);
        $this->assertStringContainsString('Алиса — разработчик', $md);
    }
}
