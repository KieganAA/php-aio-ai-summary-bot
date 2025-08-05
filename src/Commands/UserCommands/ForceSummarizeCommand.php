<?php
declare(strict_types=1);

namespace Src\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Keyboard;
use Psr\Log\LoggerInterface;
use Src\Config\Config;
use Src\Repository\DbalMessageRepository;
use Src\Service\AuthorizationService;
use Src\Service\Database;
use Src\Service\DeepseekService;
use Src\Service\LoggerService;
use Src\Service\TelegramService;
use Src\Util\TextUtils;

class ForceSummarizeCommand extends UserCommand
{
    protected $name = 'forcesummarize';
    protected $description = 'Summarize chat ignoring processed flag';
    protected $usage = '/forcesummarize';
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
        $user = $this->getMessage()->getFrom()->getUsername();
        if (!AuthorizationService::isAllowed($user)) {
            $this->logger->warning('Unauthorized forcesummarize command', ['user' => $user]);
            return $this->replyToChat('You are not allowed to use this bot.');
        }

        $this->logger->info('Force summarize command triggered', ['chat_id' => $chatId]);
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
            return $this->replyToChat('Send /forcesummarize <chat_id> [YYYY-MM-DD]', [
                'reply_markup' => $keyboard,
            ]);
        }

        [$targetId, $dateStr] = array_pad(explode(' ', $params, 2), 2, '');
        $targetId = (int)$targetId;
        $dayTs = $dateStr !== '' ? strtotime($dateStr) : time();
        if ($dayTs === false) {
            return $this->replyToChat('Invalid date format, use YYYY-MM-DD');
        }

        $msgs = $repo->getAllMessagesForChat($targetId, $dayTs);
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
            $this->logger->info('Force summary generated', ['chat_id' => $targetId]);
        } catch (\Throwable $e) {
            $this->logger->error('Force summary failed', [
                'chat_id' => $targetId,
                'error' => $e->getMessage(),
            ]);
            return $this->replyToChat('Failed to generate summary, please try again later.');
        }

        $telegram = new TelegramService();
        $response = $telegram->sendMessage(
            $chatId,
            $summary,
            'MarkdownV2'
        );
        if ($response->isOk()) {
            $this->logger->info('Force summary sent to chat', ['chat_id' => $chatId]);
        } else {
            $this->logger->error('Failed to send force summary', [
                'chat_id' => $chatId,
                'error' => $response->getDescription(),
            ]);
        }

        return $response;
    }
}
