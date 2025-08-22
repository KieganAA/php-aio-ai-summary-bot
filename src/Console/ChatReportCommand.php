<?php
declare(strict_types=1);

namespace Src\Console;

use Psr\Log\LoggerInterface;
use Src\Service\Reports\ReportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ChatReportCommand extends Command
{
    protected static $defaultName = 'app:chat-report';
    protected static $defaultDescription = 'Generate report for a single chat';

    public function __construct(private ReportService $report, private LoggerInterface $logger)
    {
        parent::__construct(self::$defaultName);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('chat', InputArgument::REQUIRED, 'Chat ID')
            ->addOption('date', null, InputOption::VALUE_OPTIONAL, 'Date in Y-m-d', date('Y-m-d'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $chatId = (int)$input->getArgument('chat');
        $dateStr = (string)$input->getOption('date');
        $ts = strtotime($dateStr) ?: time();

        $this->logger->info('Running report for chat', ['chat_id' => $chatId, 'date' => $dateStr]);
        $this->report->runReportForChat($chatId, $ts);
        $this->logger->info('Chat report finished', ['chat_id' => $chatId]);

        return Command::SUCCESS;
    }
}
