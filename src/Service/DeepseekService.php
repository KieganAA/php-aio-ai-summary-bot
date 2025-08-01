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
     * Summarise a single chunk using the strict JSON prompt.
     */
    private function summarizeChunk(
        string $chunk,
        string $chatTitle,
        int $chatId,
        string $date,
        int $chunkIndex
    ): string {
        $client = $this->client();

        $system = <<<SYS
You are ChatChunk-Summarizer-v1.
Return STRICT JSON only (no prose). Goal: capture what happened in this chat excerpt so it can be merged later.

Rules:
- Language: English. Style: concise business, past tense.
- Redact sensitive data as "***".
- Prefer signal over chatter; ignore greetings, stickers, joins/leaves, images.
- Each item ≤ 18 words. Max items: participants 10, topics 8, events 10, decisions 8, actions 10, blockers 6, questions 6.
- If nothing for a field, output [] or "" (no null).
- Do not invent facts; use "unknown" when missing.
- Times: use ISO-8601 local time for DATE and TIMEZONE when explicit, else omit time.
SYS;

        $payload = [
            'chat_title' => $chatTitle,
            'chat_id' => (string)$chatId,
            'date' => $date,
            'timezone' => 'Europe/Berlin',
            'chunk_id' => 'chunk-' . $chunkIndex,
            'transcript' => $chunk,
        ];

        $client
            ->setTemperature(0.2)
            ->setResponseFormat('json_object')
            ->query($system, 'system')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

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
        foreach ($chunks as $i => $chunk) {
            try {
                $summaries[] = $this->summarizeChunk($chunk, $chatTitle, $chatId, $date, $i + 1);
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
