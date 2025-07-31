<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Longman\TelegramBot\Exception\TelegramException;
use Src\BotHandle;
use Src\Service\LoggerService;

$logger = LoggerService::getLogger();
try {
    BotHandle::run();
} catch (TelegramException $e) {
    $logger->error('Index start failed: ' . $e->getMessage());
}