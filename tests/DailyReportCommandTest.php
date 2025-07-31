<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Src\Command\DailyReportCommand;
use Src\Service\ReportService;

class DailyReportCommandTest extends TestCase
{
    public function testCommandRunsReportService(): void
    {
        $service = $this->createMock(ReportService::class);
        $service->expects($this->once())
            ->method('runDailyReports');

        $command = new DailyReportCommand($service);
        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        $this->assertSame(0, $status);
    }
}
