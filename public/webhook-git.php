<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Src\Config\Config;
use Src\Service\LoggerService;

// Load config and logger
$projectRoot = realpath(__DIR__ . '/..');
Config::load($projectRoot . '/');
$logger = LoggerService::getLogger();

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// test

// Validate signature
$secret = Config::get('GIT_SECRET');
$headers = getallheaders();
$payload = file_get_contents('php://input');

$expectedSig = 'sha256=' . hash_hmac('sha256', $payload, $secret);
$receivedSig = $headers['X-Hub-Signature-256'] ?? '';

if (!hash_equals($expectedSig, $receivedSig)) {
    $logger->error('Invalid webhook signature', ['expected' => $expectedSig, 'received' => $receivedSig]);
    http_response_code(403);
    exit('Invalid signature');
}

// Git pull
$gitCmd = 'cd ' . escapeshellarg($projectRoot) . ' && git pull origin master 2>&1';
$gitOutput = [];
$gitStatus = null;
exec($gitCmd, $gitOutput, $gitStatus);

if ($gitStatus !== 0) {
    $logger->error('Git pull failed', ['output' => $gitOutput, 'status' => $gitStatus]);
    http_response_code(500);
    exit('Git pull failed');
}

// Composer install
$composerCmd = 'cd ' . escapeshellarg($projectRoot) . ' && /usr/bin/env composer install --no-interaction --no-progress 2>&1';
$composerOutput = [];
$composerStatus = null;
exec($composerCmd, $composerOutput, $composerStatus);

if ($composerStatus !== 0) {
    $logger->error('Composer install failed', ['output' => $composerOutput, 'status' => $composerStatus]);
    http_response_code(500);
    exit('Composer install failed');
}

// Done
$logger->info('Deployment executed successfully');

http_response_code(200);
echo "Webhook executed, Git updated, and Composer install completed.\n";