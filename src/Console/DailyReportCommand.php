<?php
declare(strict_types=1);

namespace Src\Console;

use Psr\Log\LoggerInterface;
use Src\Service\Reports\ReportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DailyReportCommand extends Command
{
    protected static $defaultName = 'app:daily-report';
    protected static $defaultDescription = 'Run daily chat summaries';

    public function __construct(private ReportService $report, private LoggerInterface $logger)
    {
        parent::__construct(self::$defaultName);
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info('Daily report command started');
        $this->report->runDailyReports(time());
        $this->logger->info('Daily report command finished');

        return Command::SUCCESS;
    }
}
