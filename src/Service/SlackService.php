<?php
declare(strict_types=1);

namespace Src\Service;

class SlackService
{
    public function __construct(private string $webhookUrl)
    {
    }

    public function sendMessage(string $text): void
    {
        if ($this->webhookUrl === '') {
            return;
        }
        $payload = json_encode(['text' => $text]);
        $ch = curl_init($this->webhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    }
}
