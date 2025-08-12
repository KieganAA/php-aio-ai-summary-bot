<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Src\Service\Integrations\DeepseekService;
use Src\Service\Reports\Generators\ExecutiveReportGenerator;

class ExecutiveReportGeneratorTest extends TestCase
{
    public function testProducesValidJson(): void
    {
        $deepseek = $this->createMock(DeepseekService::class);
        $deepseek->expects($this->once())->method('inferMood')->willReturn('neutral');
        $generator = new ExecutiveReportGenerator($deepseek);
        $json = $generator->summarize('All good here', ['chat_id' => 1, 'date' => '2025-01-01']);
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertContains($data['overall_status'], ['ok', 'warning', 'critical']);
        $expected = ['critical_chats', 'warnings', 'trending_topics', 'sla_violations', 'client_mood', 'notable_quotes'];
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }

    public function testHandlesRussianTranscript(): void
    {
        $deepseek = $this->createMock(DeepseekService::class);
        $deepseek->expects($this->once())
            ->method('inferMood')
            ->with($this->stringContains('клиент злой'))
            ->willReturn('negative');
        $generator = new ExecutiveReportGenerator($deepseek);
        $json = $generator->summarize('Это критическая ошибка, клиент злой', ['chat_id' => 1]);
        $data = json_decode($json, true);
        $this->assertSame('critical', $data['overall_status']);
        $this->assertSame('negative', $data['client_mood']);
    }
}
