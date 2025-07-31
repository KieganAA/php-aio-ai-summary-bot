<?php
declare(strict_types=1);

namespace Src\Service;

use DeepSeek\DeepSeekClient;

class DeepseekService
{
    private DeepSeekClient $client;

    public function __construct(string $apiKey)
    {
        $this->client = DeepSeekClient::build($apiKey);
    }

    public function summarize(string $transcript): string
    {
        $this->client->query(
            'Provide a concise summary focusing on tasks, issues, and decisions.',
            'system'
        );
        $this->client->query($transcript, 'user');
        $raw = $this->client->run();
        $data = json_decode($raw, true);
        if (isset($data['choices'][0]['message']['content'])) {
            return trim($data['choices'][0]['message']['content']);
        }
        return $raw;
    }
}
