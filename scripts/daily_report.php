<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Src\Config\Config;
use Src\Repository\MySQLMessageRepository;
use Src\Service\DeepseekService;
use Src\Service\ReportService;
use Src\Service\TelegramService;

Config::load(__DIR__ . '/..');
date_default_timezone_set('Europe/Moscow');

$repo = new MySQLMessageRepository();
$deepseek = new DeepseekService(Config::get('DEEPSEEK_API_KEY'));
$telegram = new TelegramService();
$report = new ReportService(
    $repo,
    $deepseek,
    $telegram,
    (int)Config::get('SUMMARY_CHAT_ID')
);

$report->runDailyReports(time());
