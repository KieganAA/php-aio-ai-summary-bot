<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Src\Service\ReportService;
use Src\Repository\MessageRepositoryInterface;
use Src\Service\DeepseekService;
use Src\Service\TelegramService;
use Src\Service\SlackService;
use Src\Service\NotionService;

class ReportServiceTest extends TestCase
{
    public function testGeneratesAndSendsReports(): void
    {
        $repo = $this->createMock(MessageRepositoryInterface::class);
        $deepseek = $this->createMock(DeepseekService::class);
        $telegram = $this->createMock(TelegramService::class);
        $slack = $this->createMock(SlackService::class);
        $notion = $this->createMock(NotionService::class);

        $day = strtotime('2025-07-31');

        $repo->expects($this->once())
            ->method('listActiveChats')
            ->with($day)
            ->willReturn([1]);

        $messages = [
            ['from_user' => 'u', 'message_date' => $day + 3600, 'text' => 'hi'],
            ['from_user' => 'v', 'message_date' => $day + 7200, 'text' => 'there'],
        ];

        $repo->expects($this->once())
            ->method('getMessagesForChat')
            ->with(1, $day)
            ->willReturn($messages);

        $transcript = "[u @ 01:00] hi\n[v @ 02:00] there\n";
        $deepseek->expects($this->once())
            ->method('summarize')
            ->with($transcript)
            ->willReturn('summary');

        $telegram->expects($this->once())
            ->method('sendMessage')
            ->with(99, "*Report for chat* `1`\n_" . date('Y-m-d', $day) . "_\n\nsummary");

        $repo->expects($this->once())
            ->method('markProcessed')
            ->with(1, $day);

        $slack->expects($this->once())
            ->method('sendMessage');

        $notion->expects($this->once())
            ->method('addReport');

        $service = new ReportService($repo, $deepseek, $telegram, 99, $slack, $notion);
        $service->runDailyReports($day);
    }

    public function testSkipsChatsWithNoMessages(): void
    {
        $repo = $this->createMock(MessageRepositoryInterface::class);
        $deepseek = $this->createMock(DeepseekService::class);
        $telegram = $this->createMock(TelegramService::class);
        $slack = $this->createMock(SlackService::class);
        $notion = $this->createMock(NotionService::class);

        $day = strtotime('2025-07-31');

        $repo->expects($this->once())
            ->method('listActiveChats')
            ->with($day)
            ->willReturn([2]);

        $repo->expects($this->once())
            ->method('getMessagesForChat')
            ->with(2, $day)
            ->willReturn([]);

        $deepseek->expects($this->never())->method('summarize');
        $telegram->expects($this->never())->method('sendMessage');
        $slack->expects($this->never())->method('sendMessage');
        $notion->expects($this->never())->method('addReport');
        $repo->expects($this->never())->method('markProcessed');

        $service = new ReportService($repo, $deepseek, $telegram, 99, $slack, $notion);
        $service->runDailyReports($day);
    }
}
