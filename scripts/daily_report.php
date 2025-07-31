<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Increase memory limit for daily summarization process
ini_set('memory_limit', '256M');

use Src\Config\Config;
use Src\Repository\DbalMessageRepository;
use Src\Service\DeepseekService;
use Src\Service\ReportService;
use Src\Service\TelegramService;
use Src\Service\LoggerService;
use Src\Service\Database;

Config::load(__DIR__ . '/..');
$logger = LoggerService::getLogger();
date_default_timezone_set('Europe/Moscow');

$conn = Database::getConnection($logger);
$repo = new DbalMessageRepository($conn, $logger);
$deepseek = new DeepseekService(Config::get('DEEPSEEK_API_KEY'));
$telegram = new TelegramService();
$logger->info('Daily report script started');
$report = new ReportService(
    $repo,
    $deepseek,
    $telegram,
    (int)Config::get('SUMMARY_CHAT_ID')
);

$report->runDailyReports(time());
$logger->info('Daily report script finished');
