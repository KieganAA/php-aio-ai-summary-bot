<?php
declare(strict_types=1);

namespace Src\Console;

use Psr\Log\LoggerInterface;
use Src\Repository\MessageRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ResetProcessedCommand extends Command
{
    protected static $defaultName = 'app:reset-processed';
    protected static $defaultDescription = 'Mark all messages as not processed';

    public function __construct(private MessageRepositoryInterface $repo, private LoggerInterface $logger)
    {
        parent::__construct(self::$defaultName);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info('Reset processed command started');
        $this->repo->resetAllProcessed();
        $this->logger->info('Reset processed command finished');
        return Command::SUCCESS;
    }
}
