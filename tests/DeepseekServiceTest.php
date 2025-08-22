<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Src\Service\Integrations\DeepseekService;

class DeepseekServiceTest extends TestCase
{
    public function testConstructorThrowsOnEmptyApiKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DeepseekService('');
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
