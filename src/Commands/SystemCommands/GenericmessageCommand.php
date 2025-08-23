<?php
// src/Commands/SystemCommands/GenericmessageCommand.php
declare(strict_types=1);

namespace Src\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Psr\Log\LoggerInterface;
use Src\Logger\MessageLogger;
use Src\Repository\DbalMessageRepository;
use Src\Service\Database;
use Src\Service\LoggerService;

class GenericmessageCommand extends SystemCommand
{
    protected $name = 'genericmessage';
    protected $description = 'Handles every incoming message';
    protected $version = '1.1.0';

    private LoggerInterface $logger;

    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->logger = LoggerService::getLogger();
    }

    public function execute(): ServerResponse
    {
        $update = $this->getUpdate();

        $conn = Database::getConnection($this->logger);
        $repo   = new DbalMessageRepository($conn, $this->logger);

        // Разрешённые чаты по заголовку: скорректируйте при необходимости
        $logger = new MessageLogger($repo, $this->logger, '/AIO/i');
        $logger->handleUpdate($update);

        return Request::emptyResponse();
    }
}
