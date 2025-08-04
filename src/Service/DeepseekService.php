<?php
declare(strict_types=1);

namespace Src\Service;

use DeepSeek\DeepSeekClient;
use GuzzleHttp\Client as HttpClient;
use Src\Util\TokenCounter;
use Src\Util\TextUtils;

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
     * Execute the API request with retries to handle transient Cloudflare
     * 525 errors (SSL handshake failed).  A small delay is applied between
     * attempts.
     */
    private function runWithRetries(DeepSeekClient $client, int $maxRetries = 3): string
    {
        $attempt = 0;
        while (true) {
            try {
                $raw = $client->run();
            } catch (\Throwable $e) {
                if (++$attempt >= $maxRetries) {
                    throw $e;
                }
                usleep(250_000); // wait 250ms before retrying
                continue;
            }

            if (stripos($raw, 'error code: 525') === false) {
                return $raw;
            }

            if (++$attempt >= $maxRetries) {
                throw new \RuntimeException('Cloudflare SSL handshake failed (error 525)');
            }
            usleep(250_000);
        }
    }

    /**
     * Extract the assistant content from a DeepSeek response.
     *
     * The API may return Server Sent Events (SSE) when streaming is enabled.
     * In that case the body consists of many `data: {json}` lines.  This helper
     * collects all `delta.content` chunks and concatenates them into the final
     * message.  If the response is a normal JSON object we just return the
     * message content as-is.
     */
    private function extractContent(string $raw): string
    {
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['choices'][0]['message']['content'])) {
            return (string) $data['choices'][0]['message']['content'];
        }

        $content = '';
        foreach (preg_split("/\r\n|\n|\r/", trim($raw)) as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, 'data:')) {
                continue;
            }
            $payload = trim(substr($line, 5));
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
     * Split transcript participants into our employees and client employees.
     *
     * @return array{0: string[], 1: string[]} [our employees, client employees]
     */
    private function extractEmployeeContext(string $transcript): array
    {
        $participants = [];
        foreach (preg_split("/\r\n|\n|\r/", trim($transcript)) as $line) {
            if (preg_match('/^\[([^\s]+)\s*@/u', $line, $m)) {
                $participants[] = $m[1];
            }
        }
        $participants = array_values(array_unique($participants));

        $employees = array_map(
            static fn(string $u) => ['username' => $u, 'nickname' => $u],
            $participants
        );
        $our = EmployeeService::deriveOurEmployees($employees);
        $ourNames = array_map(static fn(array $e) => $e['username'], $our);
        $clientNames = array_values(array_diff($participants, $ourNames));

        return [$ourNames, $clientNames];
    }

    private function formatNames(array $names): string
    {
        return empty($names) ? 'none' : implode(', ', $names);
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
- Language: Russian. Style: concise business, past tense.
- Prefer signal over chatter; ignore greetings, stickers, joins/leaves, images.
- Each item ≤ 20 words. Max items: participants 10, topics 8, events 10, decisions 8, actions 10, blockers 6, questions 6.
- If nothing for a field, output [] or "" (no null).
- Do not invent facts; use "unknown" when missing.
- Times: use ISO-8601 local time for DATE and TIMEZONE when explicit, else omit time.
SYS;

        $payload = [
            'chat_title' => $chatTitle,
            'chat_id' => (string)$chatId,
            'date' => $date,
            'timezone' => 'Europe/Moscow',
            'chunk_id' => 'chunk-' . $chunkIndex,
            'transcript' => $chunk,
        ];

        $client
            ->setTemperature(0.2)
            ->setResponseFormat('json_object')
            ->query($system, 'system')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

        $raw = $this->runWithRetries($client);
        return trim($this->extractContent($raw));
    }

    /**
     * Run the heavy global pass using a concise JSON-centred prompt.
     */
    private function finalSummary(
        string $input,
        string $chatTitle,
        int $chatId,
        string $date,
        array $ourEmployees,
        array $clientEmployees
    ): string {
        $client = $this->client();

        $our = $this->formatNames($ourEmployees);
        $clients = $this->formatNames($clientEmployees);

        $prompt = <<<PROMPT
### System
You are "ChatSummariser-v2".
You will summarise a Telegram chat excerpt into a compact JSON object.
Do not add text outside JSON. Language: Russian. Use ≤20 words per bullet.

### Participants
Our employees: {$our}
Client employees: {$clients}

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
        $raw = $this->runWithRetries($client);
        $content = $this->extractContent($raw);
        $json = $this->decodeJson($content);
        if ($json === null) {
            return TextUtils::escapeMarkdown($content);
        }

        return $this->jsonToMarkdown($json, $chatTitle, $chatId, $date);
    }

    /**
     * Attempt to decode JSON that may be wrapped in additional text or code fences.
     */
    private function decodeJson(string $content): ?array
    {
        $json = json_decode($content, true);
        if (is_array($json)) {
            return $json;
        }

        if (preg_match('/{.*}/s', $content, $m)) {
            $json = json_decode($m[0], true);
            if (is_array($json)) {
                return $json;
            }
        }

        return null;
    }

    public function jsonToMarkdown(array $data, string $chatTitle, int $chatId, string $date): string
    {

        $baseSections = [
            'topics'       => 'Темы',
            'issues'       => 'Проблемы',
            'decisions'    => 'Решения',
            'participants' => 'Участники',
        ];
        $extraSections = [
            'actions'      => 'Действия',
        ];

        $lines = [];
        $titleLine = TextUtils::escapeMarkdown($chatTitle);
        $dateLine  = TextUtils::escapeMarkdown($date);
        $lines[]   = "*{$titleLine} (ID {$chatId})* — {$dateLine}";

        foreach ($baseSections as $key => $title) {
            $items = $data[$key] ?? [];
            if (is_string($items)) {
                $items = [$items];
            }

            $lines[] = "• *{$title}*";

            if (!is_array($items) || empty($items)) {
                $lines[] = '  • Нет';
            } else {
                foreach ($items as $item) {
                    $lines[] = '  • ' . TextUtils::escapeMarkdown((string) $item);
                }
            }
        }

        foreach ($extraSections as $key => $title) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $items = $data[$key];
            if (is_string($items)) {
                $items = [$items];
            }
            $lines[] = "• *{$title}*";
            if (!is_array($items) || empty($items)) {
                $lines[] = '  • Нет';
            } else {
                foreach ($items as $item) {
                    $lines[] = '  • ' . TextUtils::escapeMarkdown((string) $item);
                }
            }
        }

        // Append any unknown sections to keep output deterministic
        $handled = array_merge(array_keys($baseSections), array_keys($extraSections));
        foreach ($data as $key => $items) {
            if (in_array($key, $handled, true)) {
                continue;
            }
            if (is_string($items)) {
                $items = [$items];
            }
            $sectionTitle = TextUtils::escapeMarkdown(ucfirst($key));
            $lines[] = "• *{$sectionTitle}*";
            if (!is_array($items) || empty($items)) {
                $lines[] = '  • Нет';
            } else {
                foreach ($items as $item) {
                    $lines[] = '  • ' . TextUtils::escapeMarkdown((string) $item);
                }
            }
        }

        return implode("\n", $lines);
    }

    public function summarizeTopic(string $transcript, string $chatTitle = '', int $chatId = 0): string
    {
        $client = $this->client();
        $prompt = "Summarize in no more than 30 words what the chat messages are about:\n" . $transcript;
        $client->setTemperature(0.2)->query($prompt, 'user');
        $raw = $this->runWithRetries($client);
        return TextUtils::escapeMarkdown(trim($this->extractContent($raw)));
    }

    public function summarize(
        string $transcript,
        string $chatTitle = '',
        int $chatId = 0,
        ?string $date = null,
        int $maxTokens = 3000
    ): string {
        $date ??= date('Y-m-d');

        [$ourEmployees, $clientEmployees] = $this->extractEmployeeContext($transcript);

        $chunks = $this->chunkTranscript($transcript, $maxTokens);
        if (count($chunks) === 1) {
            return $this->finalSummary($transcript, $chatTitle, $chatId, $date, $ourEmployees, $clientEmployees);
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
        return $this->finalSummary($summaryInput, $chatTitle, $chatId, $date, $ourEmployees, $clientEmployees);
    }
}