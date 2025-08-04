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

        $this->assertStringContainsString('Сводка чата: Chat (ID 1) — 2025-01-01', $md);
        $this->assertStringContainsString('👥 Участники: Алиса — разработчик', $md);
    }

    public function testJsonToMarkdownHandlesExtraSections(): void
    {
        $service = new DeepseekService('key');
        $data = [
            'actions' => ['Позвонить клиенту'],
        ];

        $ref = new ReflectionClass(DeepseekService::class);
        $method = $ref->getMethod('jsonToMarkdown');
        $method->setAccessible(true);

        $md = $method->invoke($service, $data, 'Chat', 1, '2025-01-01');

        $this->assertStringContainsString('📌 Действия: Позвонить клиенту', $md);
    }

    public function testDecodeJsonHandlesCodeBlock(): void
    {
        $service = new DeepseekService('key');
        $content = "Chat Summary:\n```json\n{\"a\":1}\n```";

        $ref = new ReflectionClass(DeepseekService::class);
        $method = $ref->getMethod('decodeJson');
        $method->setAccessible(true);

        $json = $method->invoke($service, $content);

        $this->assertSame(['a' => 1], $json);
    }

    public function testExtractEmployeeContext(): void
    {
        $service = new DeepseekService('key');
        $transcript = "[AIOTom @ 09:00] hi\n[vdevt @ 09:05] hi\n[client @ 09:10] yo";

        $ref = new ReflectionClass(DeepseekService::class);
        $method = $ref->getMethod('extractEmployeeContext');
        $method->setAccessible(true);

        [$our, $clients] = $method->invoke($service, $transcript);

        $this->assertSame(['AIOTom', 'vdevt'], $our);
        $this->assertSame(['client'], $clients);
    }
}
