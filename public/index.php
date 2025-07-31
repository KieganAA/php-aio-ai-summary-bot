<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Increase memory limit to avoid Monolog exhausting default 128M
ini_set('memory_limit', '256M');

use Longman\TelegramBot\Exception\TelegramException;
use Src\BotHandle;
use Src\Service\LoggerService;

$logger = LoggerService::getLogger();
try {
    BotHandle::run();
} catch (TelegramException $e) {
    $logger->error('Index start failed: ' . $e->getMessage());
}
