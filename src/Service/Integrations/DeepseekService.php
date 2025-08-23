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
use Throwable;

/**
 * DeepseekService (LLM-only, strict JSON with repair)
 * - RU-first, strict JSON.
 * - Structure-aware chunking включено.
 * - На каждом шаге: try LLM → validate → (если нужно) LLM-REPAIR → (если нужно) пустой каркас.
 */
class DeepseekService
{
    private string $apiKey;

    // Tuning
    private int $chunkTokenLimit = 3000;
    private int $reduceTokenLimit = 3000;
    private string $timezone = 'Europe/Berlin';
    private int $gapMinutes = 45;
    private bool $useStructChunking = true; // ON by default

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
                if ($attempt + 1 >= $maxRetries) throw $e;
                usleep((int)(250_000 * (2 ** $attempt)));
                continue;
            }
            if (stripos($raw, 'error code: 525') === false) return $raw;
            if ($attempt + 1 >= $maxRetries) throw new RuntimeException('Cloudflare SSL handshake failed (error 525)');
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
            if ($line === '' || !preg_match('/^data:\s*(.+)$/', $line, $m)) continue;
            $payload = trim($m[1]);
            if ($payload === '' || $payload === '[DONE]') continue;
            $json = json_decode($payload, true);
            if (isset($json['choices'][0]['delta']['content'])) {
                $content .= $json['choices'][0]['delta']['content'];
            } elseif (isset($json['choices'][0]['message']['content'])) {
                $content .= $json['choices'][0]['message']['content'];
            }
        }
        return trim($content) !== '' ? trim($content) : $raw;
    }

    // ---------------- structure-aware ----------------

    private function chunkMessages(array $messages): array
    {
        return StructChunker::chunkByStructure($messages, $this->gapMinutes, $this->timezone);
    }

    // ---------------- LLM strict helpers ----------------

    private function jsonGuardInstruction(string $locale = 'ru'): string
    {
        return $locale === 'ru'
            ? 'Ответь только валидным json-объектом. Без текста вокруг, без Markdown, без ```.'
            : 'Reply with valid json object only. No extra text, no Markdown, no ```.';
    }

    private function ensureJsonOrThrow(string $content): string
    {
        $hay = mb_strtolower($content);
        if (str_contains($hay, 'invalid_request_error') ||
            str_contains($hay, "prompt must contain the word 'json'")) {
            throw new RuntimeException('DeepSeek rejected json_object request: ' . $content);
        }
        return $content;
    }

    private function skeletonChunkSummary(): array
    {
        return [
            'chunk_id' => '',
            'date' => '',
            'timezone' => '',
            'participants' => [],
            'highlights' => [],
            'issues' => [],
            'decisions' => [],
            'actions' => [],
            'blockers' => [],
            'questions' => [],
            'timeline' => [],
            'evidence_quotes' => [
                ['message_id' => null, 'quote' => ''],
            ],
            'char_counts' => ['total' => 0],
            'tokens_estimate' => 0,
        ];
    }

    private function skeletonExecutive(int $chatId, string $date): array
    {
        return [
            'chat_id' => $chatId,
            'date' => $date,
            'verdict' => 'ok',
            'health_score' => 0,
            'client_mood' => 'нейтральный',
            'summary' => 'Данные недоступны.',
            'incidents' => [],
            'warnings' => [],
            'decisions' => [],
            'open_questions' => [],
            'sla' => ['breaches' => [], 'at_risk' => []],
            'timeline' => [],
            'notable_quotes' => [],
            'quality_flags' => ['empty'],
            'trimming_report' => [],
            'char_counts' => ['total' => 0],
            'tokens_estimate' => 0,
        ];
    }

    /**
     * Универсальный строгий вызов LLM с повтором и «ремонтом» JSON по схеме.
     * $assert must throw on invalid.
     */
    private function llmStrict(string $systemKey, array $payload, callable $assert, array $repairSkeleton, int $maxAttempts = 3): array
    {
        $attempt = 0;
        $lastErr = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $client = $this->client();
                $system = PromptLoader::system($systemKey);

                $client
                    ->setTemperature(0.1)
                    ->setResponseFormat('json_object')
                    ->query($system, 'system')
                    ->query($this->jsonGuardInstruction('ru'), 'user')
                    ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

                $raw = $this->runWithRetries($client);
                $content = $this->ensureJsonOrThrow($this->extractContent($raw));
                $json = json_decode($content, true);

                if (!is_array($json)) {
                    throw new RuntimeException('Non-JSON from LLM');
                }

                // Validate shape
                $assert($json);
                return $json;
            } catch (Throwable $e) {
                $lastErr = $e;

                // Попытка «ремонта» через LLM (без интерпретации данных локально)
                try {
                    $client2 = $this->client();
                    $repairInstruction = [
                        'role' => 'user',
                        'content' =>
                            "Почини JSON строго под следующую схему (ключи и типы). " .
                            "Заполни отсутствующие поля пустыми значениями ([], \"\", null по типу). " .
                            "Ничего не добавляй сверх схемы. Верни только валидный JSON-объект.\n\n" .
                            "SCHEMA:\n" . json_encode($repairSkeleton, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n" .
                            "ORIGINAL_OR_ERROR:\n" . ($e->getMessage())
                    ];

                    $client2
                        ->setTemperature(0.0)
                        ->setResponseFormat('json_object')
                        ->query('Ты — JSON-ремонтник. Возвращай только валидный объект.', 'system')
                        ->query($this->jsonGuardInstruction('ru'), 'user')
                        ->query($repairInstruction['content'], 'user');

                    $raw2 = $this->runWithRetries($client2);
                    $content2 = $this->ensureJsonOrThrow($this->extractContent($raw2));
                    $json2 = json_decode($content2, true);
                    if (!is_array($json2)) {
                        throw new RuntimeException('Repair failed: non-JSON');
                    }
                    $assert($json2);
                    return $json2;
                } catch (Throwable $e2) {
                    $lastErr = $e2;
                    // retry loop continues
                }
            }
        }

        // Последний шаг: вернуть пустой каркас (без эвристик, просто форма)
        if (isset($repairSkeleton['chat_id'])) {
            return $repairSkeleton; // executive skeleton
        }
        return $repairSkeleton;     // chunk skeleton
    }

    // ---------------- PUBLIC API ----------------

    /** Строгий chunk_summary: LLM → (repair) → skeleton */
    private function summarizeChunkMessagesStrict(array $chunk, string $date, int $chatId, int $chunkIndex): array
    {
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

        return $this->llmStrict(
            'chunk_summary_v5',
            $payload,
            function (array $j) {
                JsonShape::assertChunkSummary($j);
            },
            $this->skeletonChunkSummary()
        );
    }

    /** Строгий reducer: LLM → (repair) → skeleton */
    private function reduceChunksStrict(array $summaries, int $chatId, string $date): array
    {
        $payload = [
            'chat_id' => $chatId,
            'date' => $date,
            'timezone' => $this->timezone,
            'chunks' => $summaries,
            'limits' => ['list_max' => 7, 'quote_max_words' => 12],
        ];

        return $this->llmStrict(
            'final_reducer_v5',
            $payload,
            function (array $j) {
                JsonShape::assertChunkSummary($j);
            },
            $this->skeletonChunkSummary()
        );
    }

    /** Строгий executive: LLM → (repair) → skeleton */
    private function executiveStrict(array $merged, int $chatId, string $date): array
    {
        $payload = [
            'chat_id' => $chatId,
            'date' => $date,
            'timezone' => $this->timezone,
            'merged' => $merged,
            'limits' => ['list_max' => 7, 'quote_max_words' => 12],
        ];

        return $this->llmStrict(
            'executive_report_v6',
            $payload,
            function (array $j) {
                JsonShape::assertExecutive($j);
            },
            $this->skeletonExecutive($chatId, $date)
        );
    }

    /** Главный путь: messages → chunks → reducer → executive (всё строго, без эвристик) */
    public function executiveFromMessages(array $messages, array $meta): string
    {
        $chatId = (int)($meta['chat_id'] ?? 0);
        $date = (string)($meta['date'] ?? date('Y-m-d'));

        $chunks = $this->chunkMessages($messages);
        $summaries = [];
        foreach ($chunks as $i => $chunk) {
            $summaries[] = $this->summarizeChunkMessagesStrict($chunk, $date, $chatId, $i + 1);
        }
        $merged = $this->reduceChunksStrict($summaries, $chatId, $date);
        $exec = $this->executiveStrict($merged, $chatId, $date);

        return json_encode($exec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /** Текстовый путь (реже нужен) — конвертируем в messages и пускаем в основной pipeline */
    public function executiveReport(string $transcript, array $meta): string
    {
        $lines = array_filter(preg_split('/\R/u', $transcript) ?: []);
        $messages = [];
        $ts = time();
        foreach ($lines as $ln) {
            $messages[] = ['from_user' => '', 'text' => (string)$ln, 'message_date' => $ts];
            $ts += 5;
        }
        return $this->executiveFromMessages($messages, $meta);
    }

    /** Digest: LLM с мягким контролем, если не JSON — отдаём пустую структуру дня */
    public function summarizeReports(array $reports, string $date): string
    {
        try {
            $client = $this->client();
            $system = PromptLoader::system('digest_executive_v6');
            $payload = [
                'date' => $date,
                'reports' => $reports,                  // ВАЖНО: ключ "reports"
                'limits' => ['list_max' => 7],
            ];
            $client
                ->setTemperature(0.15)
                ->setResponseFormat('json_object')
                ->query($system, 'system')
                ->query($this->jsonGuardInstruction('ru'), 'user')
                ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

            $raw = $this->runWithRetries($client);
            $out = trim($this->ensureJsonOrThrow($this->extractContent($raw)));
            json_decode($out, true); // просто проверка на JSON
            return $out;
        } catch (Throwable) {
            // Пустая сводка — без эвристик
            $empty = [
                'date' => $date,
                'verdict' => 'ok',
                'scoreboard' => ['ok' => 0, 'warning' => 0, 'critical' => 0],
                'score_avg' => null,
                'top_attention' => [],
                'themes' => [],
                'risks' => [],
                'sla' => ['breaches' => [], 'at_risk' => []],
                'quality_flags' => ['empty_digest'],
                'trimming_report' => [],
            ];
            return json_encode($empty, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
    }

    /**
     * Короткий заголовок текущей темы обсуждения (1 строка, ≤80 символов).
     * Возвращает «сырую» строку без Markdown-экранирования.
     * В случае ошибки пробрасывает исключение — ReportService это поймает и покажет "Активное обсуждение".
     */
    public function summarizeTopic(string $transcript, string $chatTitle = '', int $chatId = 0): string
    {
        $client = $this->client();
        $system = PromptLoader::system('topic_summary_v3');

        $payload = [
            'transcript' => $transcript,
            'chat_title' => $chatTitle,
            'chat_id' => (string)$chatId,
        ];

        // ВНИМАНИЕ: здесь НЕ json_object — промпт просит вернуть строку
        $client
            ->setTemperature(0.2)
            ->query($system, 'system')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

        $raw = $this->runWithRetries($client);
        $out = $this->extractContent($raw);

        // Санитизация: одна строка, без кавычек/бэктиков/хвостовой пунктуации, ≤80 символов
        $out = (string)$out;
        $out = preg_replace('/\s+/u', ' ', $out) ?? $out;
        $out = trim($out);
        // убираем обрамляющие кавычки/бэктики
        if ((str_starts_with($out, '"') && str_ends_with($out, '"')) ||
            (str_starts_with($out, '“') && str_ends_with($out, '”')) ||
            (str_starts_with($out, '«') && str_ends_with($out, '»')) ||
            (str_starts_with($out, '`') && str_ends_with($out, '`'))
        ) {
            $out = mb_substr($out, 1, mb_strlen($out, 'UTF-8') - 2, 'UTF-8');
        }
        // удаляем завершающую точку/воскл/вопрос
        $out = rtrim($out, " .。!！?？;；…");
        // ограничение длины
        if (mb_strlen($out, 'UTF-8') > 80) {
            $out = mb_substr($out, 0, 80, 'UTF-8');
        }

        return $out;
    }

}
