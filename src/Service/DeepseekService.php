<?php
declare(strict_types=1);

namespace Src\Service;

use DeepSeek\DeepSeekClient;

class DeepseekService
{
    private DeepseekClient $client;

    public function __construct(string $apiKey)
    {
        $this->client = DeepseekClient::build($apiKey);
    }

    public function summarize(string $transcript): string
    {
        $this->client->query(
            'Provide a concise summary focusing on tasks, issues, and decisions.',
            'system'
        );
        $this->client->query($transcript, 'user');
        return $this->client->run();
    }
}
