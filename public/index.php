<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Src\Config\Config;

// Increase memory limit to avoid Monolog exhausting default 128M
ini_set('memory_limit', '256M');

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
