<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Src\Console\DailyDigestCommand;
use Src\Service\Reports\ReportService;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class DailyDigestCommandTest extends TestCase
{
    public function testRunsDigestService(): void
    {
        $report = $this->createMock(ReportService::class);
        $report->expects($this->once())
            ->method('runDigest')
            ->with($this->isType('int'));

        $command = new DailyDigestCommand($report, new NullLogger());
        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($application->find('app:daily-digest'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }
}
