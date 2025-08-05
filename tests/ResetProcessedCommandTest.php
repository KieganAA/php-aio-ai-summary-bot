<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Src\Console\ResetProcessedCommand;
use Src\Repository\MessageRepositoryInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class ResetProcessedCommandTest extends TestCase
{
    public function testResetsProcessed(): void
    {
        $repo = $this->createMock(MessageRepositoryInterface::class);
        $repo->expects($this->once())->method('resetAllProcessed');
        $command = new ResetProcessedCommand($repo, new NullLogger());
        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($app->find('app:reset-processed'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }
}
