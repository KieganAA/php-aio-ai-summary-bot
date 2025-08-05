<?php
declare(strict_types=1);

namespace Src\Commands\UserCommands;

use Exception;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Psr\Log\LoggerInterface;
use Src\Service\AuthorizationService;
use Src\Service\LoggerService;

/**
 * Class StartCommand
 *
 * Handles the /start command.
 */
class StartCommand extends UserCommand
{
    protected $name = 'start';
    protected $description = 'Start command';
    protected $usage = '/start';
    protected $version = '1.0.0';
    private LoggerInterface $logger;

    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->logger = LoggerService::getLogger();
    }

    /**
     * Execute the command.
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function execute(): ServerResponse
    {
        $username = $this->getMessage()->getFrom()->getUsername();
        if (!AuthorizationService::isAllowed($username)) {
            $this->logger->warning('Unauthorized /start command', ['user' => $username]);
            return $this->replyToChat('You are not allowed to use this bot.');
        }

        $chatId = $this->getMessage()->getChat()->getId();
        $text   = 'Hey';

        try {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (Exception $e) {
            $this->logger->error('Start command failed: ' . $e->getMessage());
            return Request::emptyResponse();
        }
    }
}
