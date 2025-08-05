<?php
require __DIR__ . '/../vendor/autoload.php';

use Src\Config\Config;
use Src\Service\LoggerService;

Config::load(__DIR__ . '/../');
$logger = LoggerService::getLogger();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}


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
$projectRoot = realpath(__DIR__ . '/../');
$command = sprintf('cd %s && git pull origin master && composer install --no-interaction --no-progress 2>&1', escapeshellarg($projectRoot));
exec($command, $output, $status);

if ($status !== 0) {
    $logger->error(
        'Git pull or composer install failed',
        ['output' => $output, 'status' => $status]
    );
    http_response_code(500);
    exit("Git pull or composer install failed");
}

http_response_code(200);
echo "Webhook executed, updates from Git pulled and MySQL updates applied!";