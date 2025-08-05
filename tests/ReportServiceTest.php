<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Src\Service\ReportService;
use Src\Repository\MessageRepositoryInterface;
use Src\Service\DeepseekService;
use Src\Service\TelegramService;
use Src\Service\SlackService;
use Src\Service\NotionService;
use Src\Service\ReportGeneratorFactory;
use Src\Service\ReportGeneratorInterface;

class ReportServiceTest extends TestCase
{
    public function testGeneratesAndSendsReports(): void
    {
        $repo = $this->createMock(MessageRepositoryInterface::class);
        $deepseek = $this->createMock(DeepseekService::class);
        $telegram = $this->createMock(TelegramService::class);
        $slack = $this->createMock(SlackService::class);
        $notion = $this->createMock(NotionService::class);
        $factory = $this->createMock(ReportGeneratorFactory::class);
        $factory = $this->createMock(ReportGeneratorFactory::class);
        $generator = $this->createMock(ReportGeneratorInterface::class);
        $generator->expects($this->once())
            ->method('summarize')
            ->willReturn('summary');
        $factory = $this->createMock(ReportGeneratorFactory::class);
        $factory->method('create')->with('classic')->willReturn($generator);

        $run = strtotime('2025-07-31 04:00:00');

        $repo->expects($this->once())
            ->method('listActiveChats')
            ->with($run)
            ->willReturn([1]);

        $messages = [
            ['from_user' => 'u', 'message_date' => $run - 10800, 'text' => 'hi'],
            ['from_user' => 'v', 'message_date' => $run - 7200, 'text' => 'there'],
        ];

        $repo->expects($this->once())
            ->method('getMessagesForChat')
            ->with(1, $run)
            ->willReturn($messages);

        $repo->expects($this->once())
            ->method('getChatTitle')
            ->with(1)
            ->willReturn('My Chat');

        $transcript = "[u @ 01:00] hi\n[v @ 02:00] there";
        $deepseek->expects($this->never())->method('summarize');
        $deepseek->expects($this->never())->method('summarizeTopic');

        $telegram->expects($this->once())
            ->method('sendMessage')
            ->with(
                99,
                $this->callback(function (string $msg) use ($run): bool {
                    $date = str_replace('-', '\\-', date('Y-m-d', $run));
                    return str_contains($msg, "*Report for chat* `1`\n_{$date}_")
                        && str_contains($msg, '`Messages`: 2 \\| `Participants`: 2')
                        && str_contains($msg, 'summary');
                }),
                'MarkdownV2'
            );

        $repo->expects($this->once())
            ->method('markProcessed')
            ->with(1, $run);

        $slack->expects($this->once())
            ->method('sendMessage');

        $notion->expects($this->once())
            ->method('addReport');

        $service = new ReportService($repo, $deepseek, $telegram, 99, $slack, $notion, $factory);
        $service->runDailyReports($run);
    }

    public function testMarksActiveConversation(): void
    {
        $repo = $this->createMock(MessageRepositoryInterface::class);
        $deepseek = $this->createMock(DeepseekService::class);
        $telegram = $this->createMock(TelegramService::class);
        $generator = $this->createMock(ReportGeneratorInterface::class);
        $generator->method('summarize')->willReturn('summary');
        $factory = $this->createMock(ReportGeneratorFactory::class);
        $factory->method('create')->with('classic')->willReturn($generator);

        $run = strtotime('2025-07-31 02:30:00');

        $repo->expects($this->once())
            ->method('listActiveChats')
            ->with($run)
            ->willReturn([1]);

        $messages = [
            ['from_user' => 'u', 'message_date' => $run - 3600, 'text' => 'earlier'],
            ['from_user' => 'v', 'message_date' => $run - 1800, 'text' => 'latest topic'],
        ];

        $repo->expects($this->once())
            ->method('getMessagesForChat')
            ->with(1, $run)
            ->willReturn($messages);

        $repo->expects($this->once())
            ->method('getChatTitle')
            ->with(1)
            ->willReturn('My Chat');

        $transcript = "[u @ 01:30] earlier\n[v @ 02:00] latest topic";
        $deepseek->expects($this->never())->method('summarize');

        $deepseek->expects($this->once())
            ->method('summarizeTopic')
            ->with($this->isType('string'), 'My Chat', 1)
            ->willReturn('topic');

        $telegram->expects($this->once())
            ->method('sendMessage')
            ->with(99, $this->stringContains('Сейчас обсуждают: topic'), 'MarkdownV2');

        $repo->expects($this->once())
            ->method('markProcessed')
            ->with(1, $run);

        $service = new ReportService($repo, $deepseek, $telegram, 99, null, null, $factory);
        $service->runDailyReports($run);
    }

    public function testSkipsChatsWithNoMessages(): void
    {
        $repo = $this->createMock(MessageRepositoryInterface::class);
        $deepseek = $this->createMock(DeepseekService::class);
        $telegram = $this->createMock(TelegramService::class);
        $slack = $this->createMock(SlackService::class);
        $notion = $this->createMock(NotionService::class);
        $factory = $this->createMock(ReportGeneratorFactory::class);

        $day = strtotime('2025-07-31');

        $repo->expects($this->once())
            ->method('listActiveChats')
            ->with($day)
            ->willReturn([2]);

        $repo->expects($this->once())
            ->method('getMessagesForChat')
            ->with(2, $day)
            ->willReturn([]);

        $factory->expects($this->never())->method('create');
        $telegram->expects($this->never())->method('sendMessage');
        $slack->expects($this->never())->method('sendMessage');
        $notion->expects($this->never())->method('addReport');
        $repo->expects($this->never())->method('markProcessed');

        $service = new ReportService($repo, $deepseek, $telegram, 99, $slack, $notion, $factory);
        $service->runDailyReports($day);
    }

}
