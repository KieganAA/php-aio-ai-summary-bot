<?php
require __DIR__ . '/../vendor/autoload.php';

use Src\Config\Config;
use Src\Service\LoggerService;

$projectRoot = realpath(__DIR__ . '/..');
Config::load($projectRoot . '/');
$logger = LoggerService::getLogger();

$deployUser = Config::get('DEPLOY_USER'); // optional user to run shell commands as
$wrapCommand = static function (string $cmd) use ($deployUser): string {
    if ($deployUser !== '') {
        return 'sudo -u ' . escapeshellarg($deployUser) . ' sh -c ' . escapeshellarg($cmd);
    }
    return $cmd;
};

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
    exit('Invalid signature');
}

if (!is_dir($projectRoot)) {
    $logger->error('Project root not found');
    http_response_code(500);
    exit('Project root not found');
}

$baseCmd = 'cd ' . escapeshellarg($projectRoot) . ' && ';

// Pull latest changes from the repository
$gitOutput = [];
$gitStatus = null;
exec($wrapCommand($baseCmd . 'git pull origin master 2>&1'), $gitOutput, $gitStatus);
if ($gitStatus !== 0) {
    $logger->error('Git pull failed', ['output' => $gitOutput, 'status' => $gitStatus]);
    http_response_code(500);
    exit('Git pull failed');
}

// Install Composer dependencies
$composerOutput = [];
$composerStatus = null;
exec($wrapCommand($baseCmd . '/usr/bin/env composer install --no-interaction --no-progress 2>&1'), $composerOutput, $composerStatus);
if ($composerStatus !== 0) {
    $logger->error('Composer install failed', ['output' => $composerOutput, 'status' => $composerStatus]);
    http_response_code(500);
    exit('Composer install failed');
}

http_response_code(200);
echo "Webhook executed, updates from Git pulled and MySQL updates applied!\n";
