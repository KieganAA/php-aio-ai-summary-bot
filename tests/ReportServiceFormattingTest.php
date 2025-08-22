<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Src\Service\Reports\ReportService;
use Src\Repository\MessageRepositoryInterface;
use Src\Service\Integrations\DeepseekService;
use Src\Service\Telegram\TelegramService;

class ReportServiceFormattingTest extends TestCase
{
    private function service(): ReportService
    {
        $repo = $this->createMock(MessageRepositoryInterface::class);
        $deepseek = $this->createMock(DeepseekService::class);
        $telegram = $this->createMock(TelegramService::class);

        return new ReportService($repo, $deepseek, $telegram, 1);
    }

    public function testFormatExecutiveReport(): void
    {
        $json = json_encode([
            'chat_id' => 123,
            'date' => '2024-01-01',
            'overall_status' => 'ok',
            'warnings' => ['test'],
            'client_mood' => 'happy',
        ], JSON_UNESCAPED_UNICODE);

        $service = $this->service();
        $ref = new ReflectionClass($service);
        $method = $ref->getMethod('formatExecutiveReport');
        $method->setAccessible(true);

        $expected = "*Статус*: ok\n\n*Warnings*\n• test\n\n*Client mood*: happy";
        $this->assertSame($expected, $method->invoke($service, $json));
    }

    public function testFormatExecutiveDigest(): void
    {
        $json = json_encode([
            'overall_status' => 'warning',
            'issues' => ['a', 'b'],
        ], JSON_UNESCAPED_UNICODE);

        $service = $this->service();
        $ref = new ReflectionClass($service);
        $method = $ref->getMethod('formatExecutiveDigest');
        $method->setAccessible(true);

        $expected = "*Статус*: warning\n\n*Issues*\n• a\n• b";
        $this->assertSame($expected, $method->invoke($service, $json));
    }
}
