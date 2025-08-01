<?php
declare(strict_types=1);

namespace Src\Service;

use DeepSeek\DeepSeekClient;
use GuzzleHttp\Client as HttpClient;
use Src\Util\TokenCounter;

/**
 * Wrapper around the DeepSeek client that provides a map‑reduce
 * style summarisation to avoid timeouts on very long transcripts.
 */
class DeepseekService
{
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    private function client(): DeepSeekClient
    {
        // Build a fresh client for every request to ensure a clean state.
        // Enable streaming and raise timeouts for long requests.
        $http = new HttpClient([
            'base_uri'        => 'https://api.deepseek.com/v3',
            'timeout'         => 600,
            'connect_timeout' => 30,
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
        ]);

        return (new DeepSeekClient($http))->withStream(true);
    }

    /**
     * Split a transcript into ~3000 token chunks.
     */
    private function chunkTranscript(string $transcript, int $maxTokens = 3000): array
    {
        $messages = explode("\n", trim($transcript));
        $chunks = [];
        $current = '';
        foreach ($messages as $msg) {
            $t = TokenCounter::count($msg);
            if (TokenCounter::count($current) + $t > $maxTokens) {
                $chunks[] = trim($current);
                $current = '';
            }
            $current .= $msg . "\n";
        }
        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }
        return $chunks;
    }

    /**
     * Summarise a single chunk with a tiny prompt.
     */
    private function summarizeChunk(string $chunk): string
    {
        $client = $this->client();
        $client->query(
            "Summarise the following Telegram excerpt in bullet-point English. Focus on participants, topics, decisions and tasks.",
            'system'
        );
        $client->query($chunk, 'user');
        $raw = $client->run();
        $data = json_decode($raw, true);
        return trim($data['choices'][0]['message']['content'] ?? $raw);
    }

    /**
     * Run the heavy global pass using a concise JSON-centred prompt.
     */
    private function finalSummary(string $input, string $chatTitle, int $chatId, string $date): string
    {
        $client = $this->client();

        $prompt = <<<PROMPT
### System
You are "ChatSummariser-v2".
You will summarise a Telegram chat excerpt into a compact JSON object.
Do not add text outside JSON. Language: Russian. Use ≤20 words per bullet.

### Input
CHAT_TITLE: {$chatTitle}
DATE: {$date}
TRANSCRIPT:
{$input}

### Output (JSON only)
{
  "participants": ["..."],
  "topics": ["..."],
  "issues": ["..."],
  "decisions": ["..."],
}
PROMPT;

        $client->query($prompt, 'system');
        $raw = $client->run();
        $data = json_decode($raw, true);
        $content = trim($data['choices'][0]['message']['content'] ?? $raw);
        $json = json_decode($content, true);
        if (!is_array($json)) {
            return $content;
        }

        return $this->jsonToMarkdown($json, $chatTitle, $chatId, $date);
    }

    private function jsonToMarkdown(array $data, string $chatTitle, int $chatId, string $date): string
    {
        $sections = [
            ['emoji' => '👥', 'title' => 'Участники', 'key' => 'participants'],
            ['emoji' => '💬', 'title' => 'Темы', 'key' => 'topics'],
            ['emoji' => '⚠️', 'title' => 'Проблемы', 'key' => 'issues'],
            ['emoji' => '✅', 'title' => 'Решения', 'key' => 'decisions'],
        ];

        $lines = [];
        $lines[] = '# Сводка чата';
        $lines[] = '';
        $lines[] = "Chat: {$chatTitle} (ID {$chatId})";
        $lines[] = "Date: {$date}";
        $lines[] = '';

        $num = 1;
        foreach ($sections as $section) {
            $lines[] = sprintf('%d. %s  %s', $num, $section['emoji'], $section['title']);
            $lines[] = '';
            $items = $data[$section['key']] ?? [];
            if (is_string($items)) {
                $items = [$items];
            }
            if (!is_array($items) || empty($items)) {
                $lines[] = '   * Нет';
            } else {
                foreach ($items as $item) {
                    $lines[] = '   * ' . $item;
                }
            }
            $lines[] = '';
            $num++;
        }

        return implode("\n", $lines);
    }

    public function summarize(
        string $transcript,
        string $chatTitle = '',
        int $chatId = 0,
        ?string $date = null,
        int $maxTokens = 3000
    ): string {
        $date ??= date('Y-m-d');

        $chunks = $this->chunkTranscript($transcript, $maxTokens);
        if (count($chunks) === 1) {
            return $this->finalSummary($transcript, $chatTitle, $chatId, $date);
        }

        $summaries = [];
        foreach ($chunks as $chunk) {
            try {
                $summaries[] = $this->summarizeChunk($chunk);
            } catch (\Throwable $e) {
                if ($e->getCode() === \CURLE_OPERATION_TIMEDOUT && $maxTokens > 100) {
                    return $this->summarize($transcript, $chatTitle, $chatId, $date, (int)($maxTokens / 2));
                }
                throw $e;
            }
        }

        $summaryInput = implode("\n", $summaries);
        return $this->finalSummary($summaryInput, $chatTitle, $chatId, $date);
    }
}
