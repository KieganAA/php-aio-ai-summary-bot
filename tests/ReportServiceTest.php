<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Src\Repository\MessageRepositoryInterface;
use Src\Service\Integrations\DeepseekService;
use Src\Service\Integrations\NotionService;
use Src\Service\Integrations\SlackService;
use Src\Service\Reports\Generators\ExecutiveReportGenerator;
use Src\Service\Reports\ReportService;
use Src\Service\Telegram\TelegramService;

class ReportServiceTest extends TestCase
{
    public function testGeneratesAndSendsReports(): void
    {
        $repo = $this->createMock(MessageRepositoryInterface::class);
        $deepseek = $this->createMock(DeepseekService::class);
        $telegram = $this->createMock(TelegramService::class);
        $slack = $this->createMock(SlackService::class);
        $notion = $this->createMock(NotionService::class);
        $generator = $this->createMock(ExecutiveReportGenerator::class);
        $generator->expects($this->once())
            ->method('summarize')
            ->willReturn('summary');

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
        $deepseek->expects($this->never())->method('summarizeTopic');

        $telegram->expects($this->once())
            ->method('sendMessage')
            ->with(99, $this->stringContains('summary'), 'MarkdownV2', $this->anything());

        $repo->expects($this->once())
            ->method('markProcessed')
            ->with(1, $run);

        $slack->expects($this->once())
            ->method('sendMessage');

        $notion->expects($this->once())
            ->method('addReport');

        $service = new ReportService($repo, $deepseek, $telegram, 99, $slack, $notion, $generator);
        $service->runDailyReports($run);
    }

    public function testMarksActiveConversation(): void
    {
        $repo = $this->createMock(MessageRepositoryInterface::class);
        $deepseek = $this->createMock(DeepseekService::class);
        $telegram = $this->createMock(TelegramService::class);
        $generator = $this->createMock(ExecutiveReportGenerator::class);
        $generator->method('summarize')->willReturn('summary');

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

        $deepseek->expects($this->once())
            ->method('summarizeTopic')
            ->with($this->isType('string'), 'My Chat', 1)
            ->willReturn('topic');

        $telegram->expects($this->once())
            ->method('sendMessage')
            ->with(99, $this->stringContains('Сейчас обсуждают: topic'), 'MarkdownV2', $this->anything());

        $repo->expects($this->once())
            ->method('markProcessed')
            ->with(1, $run);

        $service = new ReportService($repo, $deepseek, $telegram, 99, null, null, $generator);
        $service->runDailyReports($run);
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

        $telegram->expects($this->never())->method('sendMessage');
        $slack->expects($this->never())->method('sendMessage');
        $notion->expects($this->never())->method('addReport');
        $repo->expects($this->never())->method('markProcessed');

        $service = new ReportService($repo, $deepseek, $telegram, 99, $slack, $notion);
        $service->runDailyReports($day);
    }

    public function testFormatsExecutiveReport(): void
    {
        $repo = $this->createMock(MessageRepositoryInterface::class);
        $deepseek = $this->createMock(DeepseekService::class);
        $telegram = $this->createMock(TelegramService::class);
        $generator = $this->createMock(ExecutiveReportGenerator::class);
        $generator->method('summarize')->willReturn('{"overall_status":"ok","critical_chats":["chat1"],"warnings":["w"],"trending_topics":[],"sla_violations":[],"client_mood":"good","notable_quotes":[]}');

        $run = strtotime('2025-07-31 04:00:00');

        $repo->expects($this->once())
            ->method('listActiveChats')
            ->with($run)
            ->willReturn([1]);

        $repo->expects($this->once())
            ->method('getMessagesForChat')
            ->with(1, $run)
            ->willReturn([
                ['from_user' => 'u', 'message_date' => $run - 7200, 'text' => 'hi'],
            ]);

        $repo->expects($this->once())
            ->method('getChatTitle')
            ->with(1)
            ->willReturn('My Chat');

        $telegram->expects($this->once())
            ->method('sendMessage')
            ->with(
                99,
                $this->callback(function (string $msg): bool {
                    return strpos($msg, 'w') !== false
                        && strpos($msg, 'good') !== false;
                }),
                'MarkdownV2',
                $this->anything()
            );

        $repo->expects($this->once())
            ->method('markProcessed')
            ->with(1, $run);

        $service = new ReportService($repo, $deepseek, $telegram, 99, null, null, $generator);
        $service->runDailyReports($run);
    }

    public function testRunDigestSendsFormattedText(): void
    {
        $repo = $this->createMock(MessageRepositoryInterface::class);
        $deepseek = $this->createMock(DeepseekService::class);
        $telegram = $this->createMock(TelegramService::class);
        $generator = $this->createMock(ExecutiveReportGenerator::class);
        $generator->method('summarize')->willReturn('summary');

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

        $deepseek->expects($this->once())
            ->method('summarizeReports')
            ->with(['summary'], date('Y-m-d', $run))
            ->willReturn('{"verdict":"ok","warnings":["delay"],"date":"' . date('Y-m-d', $run) . '"}');

        $telegram->expects($this->once())
            ->method('sendMessage')
            ->with(
                99,
                $this->callback(function (string $msg): bool {
                    return str_contains($msg, '*Ежедневный дайджест*');
                }),
                'MarkdownV2',
                $this->anything()
            );

        $service = new ReportService($repo, $deepseek, $telegram, 99, null, null, $generator);
        $service->runDigest($run);
    }

}
