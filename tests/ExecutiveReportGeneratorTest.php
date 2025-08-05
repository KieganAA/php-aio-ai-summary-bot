<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Src\Service\ExecutiveReportGenerator;

class ExecutiveReportGeneratorTest extends TestCase
{
    public function testProducesValidJson(): void
    {
        $generator = new ExecutiveReportGenerator();
        $json = $generator->summarize('All good here', ['chat_id' => 1, 'date' => '2025-01-01']);
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertContains($data['overall_status'], ['ok', 'warning', 'critical']);
        $this->assertArrayNotHasKey('next_steps', $data);
        $this->assertArrayNotHasKey('responsible', $data);
    }
}
