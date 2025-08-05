<?php
declare(strict_types=1);

namespace Src\Console;

use Psr\Log\LoggerInterface;
use Src\Repository\MessageRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListChatsCommand extends Command
{
    protected static $defaultName = 'app:list-chats';
    protected static $defaultDescription = 'List all stored chats';

    public function __construct(private MessageRepositoryInterface $repo, private LoggerInterface $logger)
    {
        parent::__construct(self::$defaultName);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info('Listing chats');
        $chats = $this->repo->listChats();
        foreach ($chats as $chat) {
            $output->writeln(sprintf('%d: %s', $chat['id'], $chat['title']));
        }
        $this->logger->info('Chats listed', ['count' => count($chats)]);

        return Command::SUCCESS;
    }
}
