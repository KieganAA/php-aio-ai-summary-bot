<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Src\Config\Config;
// Raise script limits for long transcripts

ini_set('max_execution_time', '600');
ini_set('memory_limit', '1G');

Config::load(__DIR__ . '/..');

use Longman\TelegramBot\Exception\TelegramException;
use Src\BotHandle;
use Src\Service\LoggerService;

$logger = LoggerService::getLogger();
try {
    BotHandle::run();
} catch (TelegramException $e) {
    $logger->error('Index start failed: ' . $e->getMessage());
}
