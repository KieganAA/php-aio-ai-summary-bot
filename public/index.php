<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Longman\TelegramBot\Exception\TelegramException;
use Src\BotHandle;

try {
    BotHandle::run();
} catch (TelegramException $e) {
    error_log('Index start failed: ' . $e->getMessage());
}