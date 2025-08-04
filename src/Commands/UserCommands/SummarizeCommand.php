<?php
declare(strict_types=1);

namespace Src\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Psr\Log\LoggerInterface;
use Src\Config\Config;
use Src\Repository\DbalMessageRepository;
use Src\Service\Database;
use Src\Service\DeepseekService;
use Src\Service\LoggerService;
use Src\Service\TelegramService;
use Src\Util\TextUtils;

class SummarizeCommand extends UserCommand
{
    protected $name = 'summarize';
    protected $description = 'On‑demand summary of today’s chat';
    protected $usage = '/summarize';
    protected $version = '1.0.0';
    private LoggerInterface $logger;

    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->logger = LoggerService::getLogger();
    }

    public function execute(): ServerResponse
    {
        $chatId = $this->getMessage()->getChat()->getId();
        $this->logger->info('Summarize command triggered', ['chat_id' => $chatId]);
        $conn = Database::getConnection($this->logger);
        $repo = new DbalMessageRepository($conn, $this->logger);

        $params = trim($this->getMessage()->getText(true));
        if ($params === '') {
            $buttons = [];
            foreach ($repo->listChats() as $chat) {
                $label = trim(($chat['title'] ?? '') . ' (' . $chat['id'] . ')');
                $buttons[] = [$label];
            }
            $keyboard = new Keyboard([
                'keyboard' => $buttons,
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ]);
            return $this->replyToChat('Send /summarize <chat_id> [YYYY-MM-DD]', [
                'reply_markup' => $keyboard,
            ]);
        }

        [$targetId, $dateStr] = array_pad(explode(' ', $params, 2), 2, '');
        $targetId = (int)$targetId;
        $dayTs = $dateStr !== '' ? strtotime($dateStr) : time();
        if ($dayTs === false) {
            return $this->replyToChat('Invalid date format, use YYYY-MM-DD');
        }

        $msgs = $repo->getMessagesForChat($targetId, $dayTs);
        if (empty($msgs)) {
            return $this->replyToChat('No messages to summarize yet.');
        }

        $raw = TextUtils::buildTranscript($msgs);
        $cleaned = TextUtils::cleanTranscript($raw);
        $deepseek = new DeepseekService(Config::get('DEEPSEEK_API_KEY'));
        $chatTitle = $repo->getChatTitle($targetId);
        $dateStr  = date('Y-m-d', $dayTs);
        try {
            $summary = $deepseek->summarize($cleaned, $chatTitle, $targetId, $dateStr);
            $this->logger->info('Summary generated', ['chat_id' => $targetId]);

            $json = json_decode($summary, true);
            if (!is_array($json) && preg_match('/{.*}/s', $summary, $m)) {
                $json = json_decode($m[0], true);
            }
            if (is_array($json)) {
                $summary = $deepseek->jsonToMarkdown($json, $chatTitle, $targetId, $dateStr);
                $this->logger->info('Summary is valid JSON, decoding', ['summary' => $summary]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Summary generation failed', [
                'chat_id' => $targetId,
                'error' => $e->getMessage(),
            ]);
            return $this->replyToChat('Failed to generate summary, please try again later.');
        }

        $repo->markProcessed($targetId, $dayTs);
        $this->logger->info('Messages marked processed after summarize', ['chat_id' => $targetId]);

        $telegram = new TelegramService();
        $response = $telegram->sendMessage(
            $chatId,
            TextUtils::escapeMarkdown($summary),
            'MarkdownV2'
        );
        if ($response->isOk()) {
            $this->logger->info('Summary sent to chat', ['chat_id' => $chatId]);
        } else {
            $this->logger->error('Failed to send summary', [
                'chat_id' => $chatId,
                'error' => $response->getDescription(),
            ]);
        }

        return $response;
    }
}
