<?php
declare(strict_types=1);

namespace Src\Service\Integrations;

use DeepSeek\DeepSeekClient;
use GuzzleHttp\Client as HttpClient;
use InvalidArgumentException;
use RuntimeException;
use Src\Util\JsonShape;
use Src\Util\PromptLoader;
use Src\Util\StructChunker;
use Src\Util\TextUtils;
use Src\Util\TokenCounter;
use Throwable;

// NEW

// NEW

/**
 * DeepseekService (structure-aware)
 * - RU-first prompts and outputs.
 * - Structure-aware chunking (threads/time gaps/actors).
 * - Strict JSON for executive flows (response_format=json_object + shape checks up the stack).
 */
class DeepseekService
{
    private string $apiKey;

    // Tuning knobs
    private int $chunkTokenLimit = 3000;   // per-chunk transcript tokens (budget)
    private int $reduceTokenLimit = 3000;  // threshold to trigger chunking in executive
    private string $timezone = 'Europe/Berlin';
    private int $gapMinutes = 45;          // structure segmentation
    private bool $useStructChunking = true; // feature flag

    public function __construct(string $apiKey)
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new InvalidArgumentException('API key must not be empty');
        }
        $this->apiKey = $apiKey;
    }

    private function client(): DeepSeekClient
    {
        $http = new HttpClient([
            'base_uri'        => 'https://api.deepseek.com/v3',
            'timeout'         => 600,
            'connect_timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
        ]);

        return (new DeepSeekClient($http))->withStream(true);
    }

    private function runWithRetries(DeepSeekClient $client, int $maxRetries = 3): string
    {
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $raw = $client->run();
            } catch (Throwable $e) {
                if ($attempt + 1 >= $maxRetries) {
                    throw $e;
                }
                usleep((int)(250_000 * (2 ** $attempt)));
                continue;
            }

            if (stripos($raw, 'error code: 525') === false) {
                return $raw;
            }

            if ($attempt + 1 >= $maxRetries) {
                throw new RuntimeException('Cloudflare SSL handshake failed (error 525)');
            }
            usleep((int)(250_000 * (2 ** $attempt)));
        }

        throw new RuntimeException('Failed to receive valid response from DeepSeek');
    }

    private function extractContent(string $raw): string
    {
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['choices'][0]['message']['content'])) {
            return (string) $data['choices'][0]['message']['content'];
        }

        $content = '';
        foreach (preg_split("/\r\n|\n|\r/", trim($raw)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^data:\s*(.+)$/', $line, $m)) {
                continue;
            }
            $payload = trim($m[1]);
            if ($payload === '' || $payload === '[DONE]') {
                continue;
            }
            $json = json_decode($payload, true);
            if (isset($json['choices'][0]['delta']['content'])) {
                $content .= $json['choices'][0]['delta']['content'];
            } elseif (isset($json['choices'][0]['message']['content'])) {
                $content .= $json['choices'][0]['message']['content'];
            }
        }

        return trim($content) !== '' ? trim($content) : $raw;
    }

    /**
     * @return array{0: string[], 1: string[]} [our employees, client employees]
     */

    // -------------------- legacy token-only chunking --------------------
    private function chunkTranscript(string $transcript, int $maxTokens): array
    {
        $messages = explode("\n", trim($transcript));
        $chunks = [];
        $current = '';
        foreach ($messages as $msg) {
            $t = TokenCounter::count($msg);
            if (TokenCounter::count($current) + $t > $maxTokens) {
                if (trim($current) !== '') {
                    $chunks[] = trim($current);
                }
                $current = '';
            }
            $current .= $msg . "\n";
        }
        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }
        return $chunks;
    }

    // -------------------- structure-aware path --------------------
    private function chunkMessages(array $messages, int $gapMinutes): array
    {
        return StructChunker::chunkByStructure($messages, $gapMinutes, $this->timezone);
    }

    private function summarizeChunkMessages(array $chunk, string $date, int $chatId, int $chunkIndex): array
    {
        $client = $this->client();
        $system = PromptLoader::system('chunk_summary_v5');
        $payload = [
            'chat_id' => $chatId,
            'date' => $date,
            'timezone' => $this->timezone,
            'chunk_id' => 'chunk-' . $chunkIndex,
            'messages' => array_values(array_map(static function ($m) {
                return [
                    'id' => $m['message_id'] ?? ($m['id'] ?? null),
                    'ts' => isset($m['message_date']) ? date('c', (int)$m['message_date']) : ($m['ts'] ?? null),
                    'from' => $m['from_user'] ?? ($m['from'] ?? null),
                    'reply_to' => $m['reply_to'] ?? null,
                    'text' => (string)($m['text'] ?? ''),
                ];
            }, $chunk)),
            'limits' => ['list_max' => 7, 'quote_max_words' => 12],
        ];

        $client
            ->setTemperature(0.15)
            ->setResponseFormat('json_object')
            ->query($system, 'system')
            ->query($this->jsonGuardInstruction('ru'), 'user')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

        $raw = $this->runWithRetries($client);
        $content = $this->ensureJsonOrThrow($this->extractContent($raw));
        $json = json_decode($content, true) ?: [];
        JsonShape::assertChunkSummary($json);
        return $json;
    }

    private function summarizeChunk(
        string $chunk,
        string $chatTitle,
        int $chatId,
        string $date,
        int $chunkIndex
    ): string {
        $client  = $this->client();
        $system = PromptLoader::system('chunk_summary_v5');
        $payload = [
            'chat_title' => $chatTitle,
            'chat_id' => (string)$chatId,
            'date'       => $date,
            'timezone' => $this->timezone,
            'chunk_id'   => 'chunk-' . $chunkIndex,
            'transcript' => $chunk,
        ];

        $client
            ->setTemperature(0.15)
            ->setResponseFormat('json_object')
            ->query($system, 'system')
            ->query($this->jsonGuardInstruction('ru'), 'user')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');
        $raw = $this->runWithRetries($client);
        $content = $this->ensureJsonOrThrow($this->extractContent($raw));
        return trim($content);
    }

    // -------------------- PUBLIC: EXECUTIVE JSON --------------------
    /**
     * Executive report: returns STRICT JSON (EN keys, RU values).
     * $meta = ['chat_title','chat_id','date','lang' => 'ru','audience' => 'executive']
     */
    public function executiveReport(string $transcript, array $meta): string
    {
        $chatTitle = (string)($meta['chat_title'] ?? '');
        $chatId = (int)($meta['chat_id'] ?? 0);
        $date = (string)($meta['date'] ?? date('Y-m-d'));

        // For very long transcripts, compress first via chunk summaries
        $useChunks = TokenCounter::count($transcript) > $this->reduceTokenLimit;
        $chunkSummaries = null;

        if ($useChunks) {
            $chunks = $this->chunkTranscript($transcript, $this->chunkTokenLimit);
            $mini = [];
            foreach ($chunks as $i => $chunk) {
                $raw = $this->summarizeChunk($chunk, $chatTitle, $chatId, $date, $i + 1);
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $mini[] = $decoded;
                }
            }
            $chunkSummaries = $mini;
        }

        $client = $this->client();
        $system = PromptLoader::system('executive_report_v6');
        $payload = [
            'chat_title' => $chatTitle,
            'chat_id' => (string)$chatId,
            'date' => $date,
            'timezone' => $this->timezone,
            'transcript' => $useChunks ? null : $transcript,
            'chunk_summaries' => $useChunks ? $chunkSummaries : null,
            'hints' => $meta['signals'] ?? null,
        ];

        $client
            ->setTemperature(0.1)
            ->setResponseFormat('json_object')
            ->query($system, 'system')
            ->query($this->jsonGuardInstruction('ru'), 'user')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

        $raw = $this->runWithRetries($client);
        $out = trim($this->ensureJsonOrThrow($this->extractContent($raw)));
        return $out;
    }

    /** NEW: full structure-aware flow (messages → chunks → reducer → executive) */
    public function executiveFromMessages(array $messages, array $meta): string
    {
        $chatId = (int)($meta['chat_id'] ?? 0);
        $date = (string)($meta['date'] ?? date('Y-m-d'));

        $chunks = $this->chunkMessages($messages, $this->gapMinutes);
        $summaries = [];
        foreach ($chunks as $i => $chunk) {
            $summaries[] = $this->summarizeChunkMessages($chunk, $date, $chatId, $i + 1);
        }

        // Reduce
        $client = $this->client();
        $system = PromptLoader::system('final_reducer_v5');
        $payload = [
            'chat_id' => $chatId,
            'date' => $date,
            'timezone' => $this->timezone,
            'chunks' => $summaries,
            'limits' => ['list_max' => 7, 'quote_max_words' => 12],
        ];
        $client->setTemperature(0.1)
            ->setResponseFormat('json_object')
            ->query($system, 'system')
            ->query($this->jsonGuardInstruction('ru'), 'user')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');
        $raw = $this->runWithRetries($client);
        $merged = json_decode($this->ensureJsonOrThrow($this->extractContent($raw)), true) ?: [];
        JsonShape::assertChunkSummary($merged);

        // Executive
        $system2 = PromptLoader::system('executive_report_v6');
        $payload2 = [
            'chat_id' => $chatId,
            'date' => $date,
            'timezone' => $this->timezone,
            'merged' => $merged,
            'limits' => ['list_max' => 7, 'quote_max_words' => 12],
        ];
        $client2 = $this->client();
        $client2->setTemperature(0.1)
            ->setResponseFormat('json_object')
            ->query($system2, 'system')
            ->query($this->jsonGuardInstruction('ru'), 'user')
            ->query(json_encode($payload2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');
        $raw2 = $this->runWithRetries($client2);
        $content2 = $this->ensureJsonOrThrow($this->extractContent($raw2));
        return trim($content2);
    }

    // -------------------- PUBLIC: TOPIC (RU, short) --------------------
    public function summarizeTopic(string $transcript, string $chatTitle = '', int $chatId = 0): string
    {
        $client = $this->client();
        $system = PromptLoader::system('topic_summary_v3');
        $payload = [
            'transcript' => $transcript,
            'chat_title' => $chatTitle,
            'chat_id' => (string)$chatId,
        ];

        $client
            ->setTemperature(0.2)
            ->query($system, 'system')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

        $raw = $this->runWithRetries($client);
        $out = trim($this->extractContent($raw));
        $out = preg_replace('/\s+/', ' ', $out ?? '') ?? '';
        $out = rtrim((string)$out, " .。!！?？;；");
        return TextUtils::escapeMarkdown($out);
    }

    // -------------------- PUBLIC: DIGEST (executive JSON) --------------------
    public function summarizeReports(array $reports, string $date): string
    {
        $client = $this->client();
        $system = PromptLoader::system('digest_executive_v6');
        $payload = [
            'date' => $date,
            'chat_summaries' => $reports,
        ];

        $client
            ->setTemperature(0.15)
            ->setResponseFormat('json_object')
            ->query($system, 'system')
            ->query($this->jsonGuardInstruction('ru'), 'user')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

        $raw = $this->runWithRetries($client);
        $out = trim($this->ensureJsonOrThrow($this->extractContent($raw)));
        return $out;
    }

    // -------------------- PUBLIC: MOOD (RU) --------------------

    /** Returns one of: "позитивный" | "нейтральный" | "негативный". */
    public function inferMood(string $transcript): string
    {
        $client = $this->client();
        $system = PromptLoader::system('mood_v3');
        $payload = ['transcript' => $transcript];

        $client
            ->setTemperature(0.0)
            ->setResponseFormat('json_object')
            ->query($system, 'system')
            ->query($this->jsonGuardInstruction('ru'), 'user')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

        try {
            $raw = $this->runWithRetries($client);
            $content = $this->ensureJsonOrThrow($this->extractContent($raw));
            $data = json_decode($content, true);

            $mood = mb_strtolower((string)($data['mood'] ?? $data['client_mood'] ?? ''));

            // Accept EN fallbacks too
            return match (true) {
                str_starts_with($mood, 'поз') || $mood === 'positive' => 'позитивный',
                str_starts_with($mood, 'нег') || $mood === 'negative' => 'негативный',
                default => 'нейтральный',
            };
        } catch (Throwable) {
            return 'нейтральный';
        }
    }

    // -------------------- JSON helpers --------------------
    /** Короткая гарда для json_object: должна содержать слово 'json' в user-промпте */
    private function jsonGuardInstruction(string $locale = 'ru'): string
    {
        return $locale === 'ru'
            ? 'Ответь только валидным json-объектом. Без текста вокруг, без Markdown, без ```.'
            : 'Reply with valid json object only. No extra text, no Markdown, no ```.';
    }

    /** Если DeepSeek вернул служебную ошибку — бросаем исключение */
    private function ensureJsonOrThrow(string $content): string
    {
        $hay = mb_strtolower($content);
        if (str_contains($hay, 'invalid_request_error') ||
            str_contains($hay, "prompt must contain the word 'json'")) {
            throw new RuntimeException('DeepSeek rejected json_object request: ' . $content);
        }
        return $content;
    }

    private function decodeJson(string $content): ?array
    {
        $json = json_decode($content, true);
        if (is_array($json)) {
            return $json;
        }
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $json = json_decode($m[0], true);
            if (is_array($json)) {
                return $json;
            }
        }
        return null;
    }
}
