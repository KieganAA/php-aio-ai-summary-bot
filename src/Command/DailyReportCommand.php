<?php
declare(strict_types=1);

namespace Src\Command;

use Src\Service\ReportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DailyReportCommand extends Command
{
    protected static $defaultName = 'app:daily-report';

    public function __construct(private ReportService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Generate daily reports for chats');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->service->runDailyReports(time());
        return Command::SUCCESS;
    }
}
