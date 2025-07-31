<?php

use Src\Config\Config;
use Src\Service\LoggerService;

Config::load(__DIR__ . '/../');
$logger = LoggerService::getLogger();


$secret = Config::get('GIT_SECRET');
$headers = getallheaders();
$payload = file_get_contents('php://input');

$signature = hash_hmac('sha256', $payload, $secret);
if (!isset($headers['X-Hub-Signature-256']) || $headers['X-Hub-Signature-256'] !== "sha256=$signature") {
    http_response_code(403);
    exit("Invalid signature");
}

$output = [];
$status = null;
exec("cd /var/www/bot && git pull origin main && composer install 2>&1", $output, $status);

if ($status !== 0) {
    $logger->error("Git pull or composer install failed. Command output: " . implode("\n", $output));
    http_response_code(500);
    exit("Git pull or composer install failed");
}

http_response_code(200);
echo "Webhook executed, updates from Git pulled and MySQL updates applied!";