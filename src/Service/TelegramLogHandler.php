<?php
declare(strict_types=1);

namespace Src\Service;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;

class TelegramLogHandler extends AbstractProcessingHandler
{
    private TelegramService $telegram;
    private int $chatId;

    public function __construct(int $chatId, int $level = Logger::INFO, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->telegram = new TelegramService();
        $this->chatId = $chatId;
    }

    protected function write(LogRecord $record): void
    {
        $message = sprintf("%s: %s", $record->level->getName(), $record->message);
        $this->telegram->sendMessage($this->chatId, $message);
    }
}
