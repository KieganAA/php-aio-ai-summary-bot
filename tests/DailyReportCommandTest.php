<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Src\Console\DailyReportCommand;
use Src\Service\ReportService;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class DailyReportCommandTest extends TestCase
{
    public function testRunsReportService(): void
    {
        $report = $this->createMock(ReportService::class);
        $report->expects($this->once())
            ->method('runDailyReports')
            ->with($this->isType('int'));

        $command = new DailyReportCommand($report, new NullLogger());
        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($application->find('app:daily-report'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }
}
