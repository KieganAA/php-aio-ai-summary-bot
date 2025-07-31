<?php
declare(strict_types=1);

namespace Src\Service;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;

class TelegramLogHandler extends AbstractProcessingHandler
{
    private TelegramService $telegram;
    private int $chatId = -1002671594630;

    public function __construct(int $level = Logger::ERROR, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->telegram = new TelegramService();
    }

    protected function write(LogRecord $record): void
    {
        $message = sprintf("%s: %s", $record->level->getName(), (string) $record->message);
        $this->telegram->sendMessage($this->chatId, $message);
    }
}
