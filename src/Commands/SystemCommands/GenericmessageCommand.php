<?php
declare(strict_types=1);

namespace Src\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Src\Repository\DbalMessageRepository;
use Src\Service\LoggerService;
use Src\Service\Database;
use Src\Logger\MessageLogger;

class GenericmessageCommand extends SystemCommand
{
    protected $name = 'genericmessage';
    protected $description = 'Handles every incoming message';
    protected $version = '1.0.0';
    private $logger;

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
        $logger = new MessageLogger($repo);
        $logger->handleUpdate($update);

        return Request::emptyResponse();
    }
}