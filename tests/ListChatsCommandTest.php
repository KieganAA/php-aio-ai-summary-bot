<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Src\Console\ListChatsCommand;
use Src\Repository\MessageRepositoryInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class ListChatsCommandTest extends TestCase
{
    public function testOutputsChats(): void
    {
        $repo = $this->createMock(MessageRepositoryInterface::class);
        $repo->expects($this->once())
            ->method('listChats')
            ->willReturn([
                ['id' => 1, 'title' => 'Chat A'],
                ['id' => 2, 'title' => 'Chat B'],
            ]);

        $command = new ListChatsCommand($repo, new NullLogger());
        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($application->find('app:list-chats'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('1: Chat A', $tester->getDisplay());
        $this->assertStringContainsString('2: Chat B', $tester->getDisplay());
    }
}
