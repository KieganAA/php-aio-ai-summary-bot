<?php
declare(strict_types=1);

namespace Src\Service\Integrations;

use DeepSeek\DeepSeekClient;
use GuzzleHttp\Client as HttpClient;
use InvalidArgumentException;
use RuntimeException;
use Src\Service\EmployeeService;
use Src\Util\PromptLoader;
use Src\Util\TextUtils;
use Src\Util\TokenCounter;
use Throwable;
use const CURLE_OPERATION_TIMEDOUT;

/**
 * DeepseekService
 *
 * - RU-first prompts and outputs.
 * - Robust to long transcripts via chunking (map-reduce).
 * - Strict JSON for executive flows (enforced via response_format=json_object + PHP shape checks upstream).
 * - Telegram-safe classic text (no MarkdownV2 special chars emitted by LLM; we still escape on output).
 */
class DeepseekService
{
    private string $apiKey;

    // Tuning knobs
    private int $chunkTokenLimit = 3000;   // per-chunk transcript tokens
    private int $reduceTokenLimit = 3000;  // threshold to trigger chunking in executive
    private string $timezone = 'Europe/Berlin';

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

    // -------------------- MAP (chunk) --------------------

    private function summarizeChunk(
        string $chunk,
        string $chatTitle,
        int $chatId,
        string $date,
        int $chunkIndex
    ): string {
        $client  = $this->client();
        $system  = PromptLoader::system('chunk_summary');
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
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

        $raw = $this->runWithRetries($client);
        return trim($this->extractContent($raw));
    }

    // -------------------- REDUCE (classic merge) --------------------

    private function finalSummaryClassic(
        string $input,
        string $chatTitle,
        int $chatId,
        string $date,
        array $ourEmployees,
        array $clientEmployees
    ): string {
        $client  = $this->client();
        $system = PromptLoader::system('final_summary'); // classic merge JSON
        $payload = [
            'chat_title'       => $chatTitle,
            'chat_id' => (string)$chatId,
            'date'             => $date,
            'our_employees'    => $ourEmployees,
            'client_employees' => $clientEmployees,
            'chunk_summaries' => $input, // concatenated JSONs from map step
            'hints' => $meta['signals'] ?? null,
        ];

        $client
            ->setTemperature(0.15)
            ->setResponseFormat('json_object')
            ->query($system, 'system')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

        $raw     = $this->runWithRetries($client);
        $content = $this->extractContent($raw);
        $json    = $this->decodeJson($content);
        if ($json === null) {
            // return raw (escaped) if LLM misbehaved
            return TextUtils::escapeMarkdown($content);
        }

        return $this->jsonToMarkdown($json, $chatTitle, $chatId, $date);
    }

    // -------------------- PUBLIC: CLASSIC --------------------

    /**
     * Classic, RU text (Telegram-safe). Uses map-reduce for long transcripts.
     * $meta = ['chat_title','chat_id','date','lang' => 'ru','audience' => 'team']
     */
    public function summarizeClassic(string $transcript, array $meta): string
    {
        $chatTitle = (string)($meta['chat_title'] ?? '');
        $chatId = (int)($meta['chat_id'] ?? 0);
        $date = (string)($meta['date'] ?? date('Y-m-d'));

        [$ourEmployees, $clientEmployees] = $this->extractEmployeeContext($transcript);

        $chunks = $this->chunkTranscript($transcript, $this->chunkTokenLimit);
        if (count($chunks) === 1) {
            // no need to map; synthesize one chunk summary inline
            $one = $this->summarizeChunk($transcript, $chatTitle, $chatId, $date, 1);
            return $this->finalSummaryClassic($one, $chatTitle, $chatId, $date, $ourEmployees, $clientEmployees);
        }

        $summaries = [];
        foreach ($chunks as $i => $chunk) {
            try {
                $summaries[] = $this->summarizeChunk($chunk, $chatTitle, $chatId, $date, $i + 1);
            } catch (Throwable $e) {
                if ($e->getCode() === CURLE_OPERATION_TIMEDOUT && $this->chunkTokenLimit > 1000) {
                    // shrink chunk size and retry whole classic path
                    $this->chunkTokenLimit = (int)max(1000, $this->chunkTokenLimit / 2);
                    return $this->summarizeClassic($transcript, $meta);
                }
                throw $e;
            }
        }

        $summaryInput = implode("\n", $summaries); // concatenated JSONs
        return $this->finalSummaryClassic($summaryInput, $chatTitle, $chatId, $date, $ourEmployees, $clientEmployees);
    }

    // Keep legacy name for backward-compatibility (calls classic).
    public function summarize(
        string  $transcript,
        string  $chatTitle = '',
        int     $chatId = 0,
        ?string $date = null,
        int     $maxTokens = 3000
    ): string
    {
        $date ??= date('Y-m-d');
        return $this->summarizeClassic($transcript, [
            'chat_title' => $chatTitle,
            'chat_id' => $chatId,
            'date' => $date,
            'lang' => 'ru',
            'audience' => 'team',
        ]);
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
        $evidence = null;

        if ($useChunks) {
            $chunks = $this->chunkTranscript($transcript, $this->chunkTokenLimit);
            $mini = [];
            foreach ($chunks as $i => $chunk) {
                $mini[] = $this->summarizeChunk($chunk, $chatTitle, $chatId, $date, $i + 1);
            }
            $evidence = implode("\n", $mini); // concatenated JSONs
        }

        $client = $this->client();
        $system = PromptLoader::system('executive_report');
        $payload = [
            'chat_title' => $chatTitle,
            'chat_id' => (string)$chatId,
            'date' => $date,
            'timezone' => $this->timezone,
            'transcript' => $useChunks ? null : $transcript,
            'chunk_summaries' => $useChunks ? $evidence : null,
            'hints' => $meta['signals'] ?? null,
        ];

        $client
            ->setTemperature(0.1)
            ->setResponseFormat('json_object')
            ->query($system, 'system')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

        $raw = $this->runWithRetries($client);
        return trim($this->extractContent($raw)); // ReportService will shape/validate
    }

    // -------------------- PUBLIC: TOPIC (RU, short) --------------------

    public function summarizeTopic(string $transcript, string $chatTitle = '', int $chatId = 0): string
    {
        $client = $this->client();
        $system = PromptLoader::system('topic_summary');
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
        // Make it Telegram-safe & concise
        $out = preg_replace('/\s+/', ' ', $out ?? '') ?? '';
        $out = rtrim((string)$out, " .。!！?？;；");
        return TextUtils::escapeMarkdown($out);
    }

    // -------------------- PUBLIC: DIGEST (classic text or executive JSON) --------------------

    public function summarizeReports(array $reports, string $date, string $style = 'executive'): string
    {
        if (strtolower($style) !== 'executive') {
            $client = $this->client();
            $system = PromptLoader::system('digest_classic', ['date' => $date]);
            $payload = ['chat_summaries' => $reports];

            $client
                ->setTemperature(0.2)
                ->query($system, 'system')
                ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

            $raw = $this->runWithRetries($client);
            return TextUtils::escapeMarkdown(trim($this->extractContent($raw)));
        }

        $client = $this->client();
        $system = PromptLoader::system('digest_executive');
        $payload = [
            'date' => $date,
            'chat_summaries' => $reports,
        ];

        $client
            ->setTemperature(0.15)
            ->setResponseFormat('json_object')
            ->query($system, 'system')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

        $raw = $this->runWithRetries($client);
        return trim($this->extractContent($raw));
    }

    // -------------------- PUBLIC: MOOD (RU) --------------------

    /**
     * Returns one of: "позитивный" | "нейтральный" | "негативный".
     */
    public function inferMood(string $transcript): string
    {
        $client = $this->client();
        $system = PromptLoader::system('mood');
        $payload = ['transcript' => $transcript];

        $client
            ->setTemperature(0.0)
            ->setResponseFormat('json_object')
            ->query($system, 'system')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

        try {
            $raw = $this->runWithRetries($client);
            $content = $this->extractContent($raw);
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

    /**
     * Convert classic merged JSON into Telegram-friendly MarkdownV2 text (Russian titles).
     */
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
            'events'       => 'События',
            'blockers'     => 'Блокеры',
            'questions'    => 'Вопросы',
        ];

        $lines = [];
        $titleWithId = TextUtils::escapeMarkdown("{$chatTitle} (ID {$chatId})");
        $dateLine    = TextUtils::escapeMarkdown($date);
        $lines[]     = "*{$titleWithId}* — {$dateLine}";

        foreach ($baseSections as $key => $title) {
            $items = $data[$key] ?? [];
            if (is_string($items)) {
                $items = [$items];
            }

            $sectionTitle = TextUtils::escapeMarkdown($title);
            $lines[]      = "*{$sectionTitle}*";

            if (!is_array($items) || empty($items)) {
                $lines[] = '• Нет';
            } else {
                foreach ($items as $item) {
                    $lines[] = '• ' . TextUtils::escapeMarkdown((string)$item);
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
            $sectionTitle = TextUtils::escapeMarkdown($title);
            $lines[]      = "*{$sectionTitle}*";
            if (!is_array($items) || empty($items)) {
                $lines[] = '• Нет';
            } else {
                foreach ($items as $item) {
                    $lines[] = '• ' . TextUtils::escapeMarkdown((string)$item);
                }
            }
        }

        // Append any unknown sections deterministically
        $handled = array_merge(array_keys($baseSections), array_keys($extraSections));
        foreach ($data as $key => $items) {
            if (in_array($key, $handled, true)) {
                continue;
            }
            if (is_string($items)) {
                $items = [$items];
            }
            $sectionTitle = TextUtils::escapeMarkdown(ucfirst((string)$key));
            $lines[]      = "*{$sectionTitle}*";
            if (!is_array($items) || empty($items)) {
                $lines[] = '• Нет';
            } else {
                foreach ($items as $item) {
                    $lines[] = '• ' . TextUtils::escapeMarkdown((string)$item);
                }
            }
        }

        return implode("\n", $lines);
    }
}
