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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Ensure the request body contains valid JSON to avoid unnecessary
// exceptions when bots or scanners hit the endpoint.
$body = file_get_contents('php://input');
if ($body === '' || json_decode($body, true) === null) {
    http_response_code(200);
    exit('Invalid JSON');
}

try {
    BotHandle::run();
} catch (TelegramException $e) {
    $logger->error('Index start failed: ' . $e->getMessage());
}
