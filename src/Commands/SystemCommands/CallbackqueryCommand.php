<?php
declare(strict_types=1);

namespace Src\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Request;
use Psr\Log\LoggerInterface;
use Src\Config\Config;
use Src\Repository\DbalMessageRepository;
use Src\Service\Database;
use Src\Service\DeepseekService;
use Src\Service\LoggerService;
use Src\Service\TelegramService;
use Src\Util\TextUtils;

class CallbackqueryCommand extends SystemCommand
{
    protected $name = 'callbackquery';
    protected $description = 'Handle callback queries';
    protected $version = '1.0.0';

    private LoggerInterface $logger;

    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->logger = LoggerService::getLogger();
    }

    public function execute(): ServerResponse
    {
        $callback = $this->getCallbackQuery();
        $data = $callback->getData();
        $message = $callback->getMessage();
        $chatId = $message->getChat()->getId();

        $conn = Database::getConnection($this->logger);
        $repo = new DbalMessageRepository($conn, $this->logger);

        if (strpos($data, 'sum_c_') === 0) {
            $targetChatId = (int)substr($data, strlen('sum_c_'));
            $response = $this->sendDateSelector($repo, $chatId, $targetChatId, (int)date('Y'), (int)date('m'), $message->getMessageId());
            $callback->answer();
            return $response;
        }

        if (preg_match('/^sum_m_(\-?\d+)_(\d{4})-(\d{2})_(prev|next)$/', $data, $m)) {
            $targetChatId = (int)$m[1];
            $year = (int)$m[2];
            $month = (int)$m[3];
            $dir = $m[4];
            $month += ($dir === 'next') ? 1 : -1;
            if ($month < 1) {
                $month = 12;
                $year--;
            } elseif ($month > 12) {
                $month = 1;
                $year++;
            }
            $response = $this->sendDateSelector($repo, $chatId, $targetChatId, $year, $month, $message->getMessageId());
            $callback->answer();
            return $response;
        }

        if (preg_match('/^sum_d_(\-?\d+)_(\d{4}-\d{2}-\d{2})$/', $data, $m)) {
            $targetChatId = (int)$m[1];
            $dateStr = $m[2];
            Request::editMessageText([
                'chat_id' => $chatId,
                'message_id' => $message->getMessageId(),
                'text' => 'Generating report...',
                'reply_markup' => new InlineKeyboard([]),
            ]);
            $response = $this->summarizeChat($repo, $targetChatId, $dateStr, $chatId);
            $callback->answer();
            return $response;
        }

        return $callback->answer();
    }

    private function sendDateSelector(DbalMessageRepository $repo, int $replyChatId, int $targetChatId, int $year, int $month, int $messageId): ServerResponse
    {
        $days = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $keyboard = new InlineKeyboard([]);
        $row = [];
        for ($d = 1; $d <= $days; $d++) {
            $row[] = [
                'text' => (string)$d,
                'callback_data' => sprintf('sum_d_%d_%04d-%02d-%02d', $targetChatId, $year, $month, $d),
            ];
            if (count($row) === 7) {
                $keyboard->addRow(...$row);
                $row = [];
            }
        }
        if (!empty($row)) {
            $keyboard->addRow(...$row);
        }
        $keyboard->addRow(
            ['text' => 'Prev', 'callback_data' => sprintf('sum_m_%d_%04d-%02d_prev', $targetChatId, $year, $month)],
            ['text' => 'Next', 'callback_data' => sprintf('sum_m_%d_%04d-%02d_next', $targetChatId, $year, $month)]
        );
        $chatTitle = $repo->getChatTitle($targetChatId);
        $text = sprintf('Select date for %s (%04d-%02d)', $chatTitle, $year, $month);

        return Request::editMessageText([
            'chat_id' => $replyChatId,
            'message_id' => $messageId,
            'text' => $text,
            'reply_markup' => $keyboard,
        ]);
    }

    private function summarizeChat(DbalMessageRepository $repo, int $targetId, string $dateStr, int $replyChatId): ServerResponse
    {
        $dayTs = strtotime($dateStr);
        if ($dayTs === false) {
            return $this->replyToChat('Invalid date format, use YYYY-MM-DD');
        }

        $msgs = $repo->getMessagesForChat($targetId, $dayTs);
        if (empty($msgs)) {
            $telegram = new TelegramService($this->logger);
            return $telegram->sendMessage($replyChatId, 'No messages to summarize yet.');
        }

        $raw = TextUtils::buildTranscript($msgs);
        $cleaned = TextUtils::cleanTranscript($raw);
        $deepseek = new DeepseekService(Config::get('DEEPSEEK_API_KEY'));
        $chatTitle = $repo->getChatTitle($targetId);
        $dateStr = date('Y-m-d', $dayTs);
        try {
            $summary = $deepseek->summarize($cleaned, $chatTitle, $targetId, $dateStr);
            $this->logger->info('Summary generated', ['chat_id' => $targetId]);
        } catch (\Throwable $e) {
            $this->logger->error('Summary generation failed', [
                'chat_id' => $targetId,
                'error' => $e->getMessage(),
            ]);
            return $this->replyToChat('Failed to generate summary, please try again later.');
        }

        $repo->markProcessed($targetId, $dayTs);
        $telegram = new TelegramService($this->logger);
        $response = $telegram->sendMessage($replyChatId, $summary, 'MarkdownV2');
        if ($response->isOk()) {
            $this->logger->info('Summary sent to chat', ['chat_id' => $replyChatId]);
        } else {
            $this->logger->error('Failed to send summary', [
                'chat_id' => $replyChatId,
                'error' => $response->getDescription(),
            ]);
        }

        return $response;
    }
}

