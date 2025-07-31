<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Src\Config\Config;
use Src\Repository\MySQLMessageRepository;
use Src\Service\DeepseekService;
use Src\Service\ReportService;
use Src\Service\TelegramService;
use Src\Service\LoggerService;

Config::load(__DIR__ . '/..');
$logger = LoggerService::getLogger();
date_default_timezone_set('Europe/Moscow');

$repo = new MySQLMessageRepository();
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
