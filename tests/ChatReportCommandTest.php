<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Src\Console\ChatReportCommand;
use Src\Service\Reports\ReportService;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class ChatReportCommandTest extends TestCase
{
    public function testRunsReportService(): void
    {
        $report = $this->createMock(ReportService::class);
        $report->expects($this->once())
            ->method('runReportForChat')
            ->with(123, $this->isType('int'));

        $command = new ChatReportCommand($report, new NullLogger());
        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($application->find('app:chat-report'));
        $tester->execute(['chat' => '123']);
        $tester->assertCommandIsSuccessful();
    }
}
