<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Src\Service\DeepseekService;

class DeepseekServiceTest extends TestCase
{
    public function testConstructorThrowsOnEmptyApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeepseekService('');
    }

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

        $this->assertStringContainsString('*Chat \(ID 1\)* — 2025\\-01\\-01', $md);
        $this->assertStringContainsString('*Участники*', $md);
        $this->assertStringContainsString('  • Алиса — разработчик', $md);
    }

    public function testJsonToMarkdownHandlesOptionalSections(): void
    {
        $service = new DeepseekService('key');
        $data = [
            'actions' => ['Позвонить клиенту'],
            'events' => ['Обновили сайт'],
            'blockers' => ['Нет доступа'],
            'questions' => ['Когда релиз?'],
        ];

        $ref = new ReflectionClass(DeepseekService::class);
        $method = $ref->getMethod('jsonToMarkdown');
        $method->setAccessible(true);

        $md = $method->invoke($service, $data, 'Chat', 1, '2025-01-01');

        $this->assertStringContainsString('*Действия*', $md);
        $this->assertStringContainsString('  • Позвонить клиенту', $md);
        $this->assertStringContainsString('*События*', $md);
        $this->assertStringContainsString('  • Обновили сайт', $md);
        $this->assertStringContainsString('*Блокеры*', $md);
        $this->assertStringContainsString('  • Нет доступа', $md);
        $this->assertStringContainsString('*Вопросы*', $md);
        $this->assertStringContainsString('  • Когда релиз?', $md);
    }

    public function testJsonToMarkdownEscapesValues(): void
    {
        $service = new DeepseekService('key');
        $data = [
            'topics' => ['Need _attention_ and *fix*'],
        ];

        $ref = new ReflectionClass(DeepseekService::class);
        $method = $ref->getMethod('jsonToMarkdown');
        $method->setAccessible(true);

        $md = $method->invoke($service, $data, 'Chat_', 1, '2025-01-01');

        $this->assertStringContainsString('*Chat\_ \(ID 1\)* — 2025\-01\-01', $md);
        $this->assertStringContainsString('Need \\_attention\\_ and \\*fix\\*', $md);
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

    public function testExtractContentParsesSse(): void
    {
        $service = new DeepseekService('key');
        $raw = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n" .
               "data: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n" .
               "data: [DONE]\n";

        $ref = new ReflectionClass(DeepseekService::class);
        $method = $ref->getMethod('extractContent');
        $method->setAccessible(true);

        $content = $method->invoke($service, $raw);
        $this->assertSame('Hello world', $content);
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
