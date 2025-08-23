<?php
declare(strict_types=1);

namespace Src\Service\Integrations;

use DeepSeek\DeepSeekClient;
use GuzzleHttp\Client as HttpClient;
use InvalidArgumentException;
use RuntimeException;
use Src\Util\JsonShape;
use Src\Util\StructChunker;
use Throwable;

/**
 * DeepseekService (LLM-only, strict JSON with repair)
 * - RU-first, строгий JSON.
 * - Структурное чанкирование включено по умолчанию.
 * - Каждый шаг: LLM → validate → (если нужно) LLM-REPAIR (с исходным ответом) → (если нужно) skeleton.
 * - Промты встроены и содержат ПОЛНЫЕ формы OUTPUT SHAPE (без ссылок на «SCHEMAS.*»).
 * - Анти-фабрикация: «только из входа, не выдумывать». Пустое — пустым.
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

    // ---------------- prompts (inline, with explicit OUTPUT SHAPE & anti-fabrication) ----------------

    private function systemPrompt(string $key): string
    {
        static $map = null;
        if ($map === null) {
            $map = [
                'chunk_summary_v5' => <<<'TXT'
YOU ARE: Structure-aware summarizer for Telegram chat chunks. RU-first, EN keys. Strict JSON only.

INPUT:
{
  "chat_id": number,
  "date": "YYYY-MM-DD",
  "timezone": string,
  "chunk_id": string,
  "messages": [
    {"id": number|null, "ts": "ISO8601"|null, "from": "string"|null, "reply_to": number|null, "text": "string"}
  ],
  "limits": {"list_max": 7, "quote_max_words": 12}
}

GOAL:
Зафиксировать факты текущего фрагмента ЧИСТО по входным сообщениям — без выдумок — для дальнейшей агрегации.

STRICTNESS / ANTI-FABRICATION:
- Верни ТОЛЬКО валидный JSON-объект. Без Markdown/текста/бэктиков.
- RU-first текст; EN-ключи. Пустое → [] или "".
- НЕЛЬЗЯ добавлять факты, которых нет во входных messages. Если не уверены — пропусти.
- Каждый элемент должен быть перефразом/агрегацией реальных сообщений из messages.
- evidence_quotes — ТОЛЬКО прямые короткие цитаты из messages (≤12 слов). Если не из входа — не включать.
- participants — из полей "from" во входе.
- char_counts.total — суммарная длина text по всем messages (в символах UTF-8).
- tokens_estimate — приблизительно char_counts.total / 4 (целое вниз).
- HARD RULE: "chunk_id" в ответе ДОЛЖЕН ТОЧНО совпадать со входным "chunk_id".

OUTPUT SHAPE (верни РОВНО этот объект, без лишних ключей):
{
  "chunk_id": "string",
  "date": "string",
  "timezone": "string",
  "participants": ["string"],
  "highlights": ["string"],
  "issues": ["string"],
  "decisions": ["string"],
  "actions": ["string"],
  "blockers": ["string"],
  "questions": ["string"],
  "timeline": ["string"],
  "evidence_quotes": [
    {"message_id": "number|null", "quote": "string (≤12 слов)"}
  ],
  "char_counts": {"total": "number"},
  "tokens_estimate": "number"
}
TXT,
                'final_reducer_v5' => <<<'TXT'
YOU ARE: Deterministic reducer for multiple chunk_summary objects of one chat/day. RU-first, EN keys. Strict JSON only.

INPUT:
{
  "chat_id": number,
  "date": "YYYY-MM-DD",
  "timezone": string,
  "expected_chunk_id": "string",
  "chunks": [<chunk_summary objects>],
  "limits": {"list_max": 7, "quote_max_words": 12}
}

TASK:
Слить фрагменты: удалить повторы/шум, сохранить факты/числа/даты и короткие цитаты-якоря.

STRICTNESS / ANTI-FABRICATION:
- Используй ТОЛЬКО данные из входных chunks. Ничего нового не придумывать.
- Все списки — объединение/дедупликация соответствующих полей chunks.
- evidence_quotes — только из входных chunks.evidence_quotes.
- char_counts.total = сумма по chunks.char_counts.total.
- tokens_estimate = сумма по chunks.tokens_estimate.
- HARD RULE: "chunk_id" в ответе ДОЛЖЕН ТОЧНО равняться "expected_chunk_id".

OUTPUT SHAPE (ровно этот объект, без лишних ключей):
{
  "chunk_id": "string",
  "date": "string",
  "timezone": "string",
  "participants": ["string"],
  "highlights": ["string"],
  "issues": ["string"],
  "decisions": ["string"],
  "actions": ["string"],
  "blockers": ["string"],
  "questions": ["string"],
  "timeline": ["string"],
  "evidence_quotes": [
    {"message_id": "number|null", "quote": "string (≤12 слов)"}
  ],
  "char_counts": {"total": "number"},
  "tokens_estimate": "number"
}
TXT,
                'executive_report_v6' => <<<'TXT'
YOU ARE: Executive reporter for one chat/day. RU-first, EN keys. Strict JSON only.

INPUT:
{
  "chat_id": number,
  "date": "YYYY-MM-DD",
  "timezone": string,
  "merged": <chunk_summary object>,
  "limits": {"list_max": 7, "quote_max_words": 12}
}

OBJECTIVE:
Короткий управленческий отчёт: инциденты, риски, решения, SLA, настроение клиента, открытые вопросы.

STRICTNESS / ANTI-FABRICATION:
- Основание — ТОЛЬКО поля входного "merged" (highlights/issues/decisions/blockers/questions/timeline/evidence_quotes).
- НЕЛЬЗЯ добавлять факты не из merged. Нет данных → пустые списки.
- summary — одна строка ≤280 символов, RU, и должна быть резюме ТОЛЬКО на основе merged.
- incidents/warnings/decisions/open_questions/sla/timeline/notable_quotes — всё выводится ТОЛЬКО из merged (перефраз/сжатие).
- client_mood ∈ {"позитивный","нейтральный","негативный"}; при сомнении — "нейтральный".
- verdict: "critical" при очевидных критичных фактах из merged (breach/блокер/риск денег); "warning" при заметных рисках; иначе "ok".
- health_score: 0–100 (выше — лучше). Если merged почти пуст — 80–100; есть проблемы — 40–79; критично — 0–39.
- char_counts/tokens_estimate — скопировать из merged.
- trimming_report/quality_flags — по merged (например, малое покрытие, дубликаты, пропуски времени).

OUTPUT SHAPE (ровно этот объект, без лишних ключей):
{
  "chat_id": 0,
  "date": "YYYY-MM-DD",
  "verdict": "ok|warning|critical",
  "health_score": 0,
  "client_mood": "позитивный|нейтральный|негативный",
  "summary": "string",
  "incidents": [
    {"title": "string", "impact": "string", "status": "resolved|unresolved", "severity": "low|medium|high", "evidence": ["string"]}
  ],
  "warnings": ["string"],
  "decisions": ["string"],
  "open_questions": ["string"],
  "sla": {"breaches": ["string"], "at_risk": ["string"]},
  "timeline": ["string"],
  "notable_quotes": ["string"],
  "quality_flags": ["string"],
  "trimming_report": {"initial_messages":0,"kept_messages":0,"kept_clusters":0,"primary_discard_rules":["string"],"potential_loss_risks":["string"]},
  "char_counts": {"total": 0},
  "tokens_estimate": 0
}
TXT,
                'digest_executive_v6' => <<<'TXT'
YOU ARE: Aggregator of daily executive reports across chats. RU-first, EN keys. Strict JSON only.

INPUT:
{
  "date": "YYYY-MM-DD",
  "reports": [<executive_report object OR JSON string>],
  "limits": {"list_max": 7}
}

NORMALIZE:
- Каждый элемент в "reports" может быть объектом или JSON-строкой. Если строка — распарси. Нераспарсибельные — игнорируй.

ANTI-FABRICATION:
- Никаких новых фактов. Все элементы формируются ТОЛЬКО из входных отчётов.
- themes/risks/sla.* — это агрегированные/объединённые фразы ИСКЛЮЧИТЕЛЬНО из полей отчётов (incidents/warnings/decisions/open_questions/summary/sla/timeline/notable_quotes). Новые формулировки не придумывать.
- top_attention — только из отчётов с verdict ∈ {"warning","critical"}.

RULES:
- verdict дня: если есть хоть один critical → "critical"; иначе если есть warning → "warning"; иначе "ok".
- score_avg: среднее health_score по валидным отчётам, округлить до целого (или null, если отчётов нет).
- scoreboard: посчитать ok/warning/critical.
- top_attention: до 7 чатов (critical/warning) с минимальным health_score; включить краткое summary и до 3 key_points (взятых из соответствующих полей отчёта).
- trimming_report: {"reports_in":N,"reports_kept":N,"rules":["string"]}.

OUTPUT SHAPE (ровно этот объект, без лишних ключей):
{
  "date": "YYYY-MM-DD",
  "verdict": "ok|warning|critical",
  "scoreboard": {"ok":0,"warning":0,"critical":0},
  "score_avg": 0,
  "top_attention": [
    {"chat_id": 0, "verdict": "warning|critical", "health_score": 0, "summary": "string", "key_points": ["string"]}
  ],
  "themes": ["string"],
  "risks": ["string"],
  "sla": {"breaches":["string"], "at_risk":["string"]},
  "quality_flags": ["string"],
  "trimming_report": {"reports_in":0,"reports_kept":0,"rules":["string"]}
}
TXT,
                'topic_summary_v3' => <<<'TXT'
YOU ARE: Topic grouper for active discussion.
TASK: Верни одну строку — короткий заголовок темы (до 80 символов, без кавычек и точки).
OUTPUT: одна строка текста (RU), без форматирования.
TXT,
            ];
        }
        return $map[$key] ?? '';
    }

    // ---------------- LLM strict helpers ----------------

    private function jsonGuardInstruction(): string
    {
        return 'Ответь только валидным json-объектом. Без текста вокруг, без Markdown, без ```.';
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
            'evidence_quotes' => [],
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
            'trimming_report' => ["initial_messages" => 0, "kept_messages" => 0, "kept_clusters" => 0, "primary_discard_rules" => [], "potential_loss_risks" => []],
            'char_counts' => ['total' => 0],
            'tokens_estimate' => 0,
        ];
    }

    private function skeletonDigest(string $date): array
    {
        return [
            'date' => $date,
            'verdict' => 'ok',
            'scoreboard' => ['ok' => 0, 'warning' => 0, 'critical' => 0],
            'score_avg' => null,
            'top_attention' => [],
            'themes' => [],
            'risks' => [],
            'sla' => ['breaches' => [], 'at_risk' => []],
            'quality_flags' => ['empty_digest'],
            'trimming_report' => ['reports_in' => 0, 'reports_kept' => 0, 'rules' => []],
        ];
    }

    /**
     * Универсальный строгий вызов LLM с повтором и «ремонтом» JSON по схеме.
     * В ремонт передаём исходный ответ модели.
     */
    private function llmStrict(string $systemKey, array $payload, callable $assert, array $repairSkeleton, int $maxAttempts = 3): array
    {
        $attempt = 0;
        $lastModelText = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $client = $this->client();
                $system = $this->systemPrompt($systemKey);

                $client
                    ->setTemperature(0.1)
                    ->setResponseFormat('json_object')
                    ->query($system, 'system')
                    ->query($this->jsonGuardInstruction(), 'user')
                    ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

                $raw = $this->runWithRetries($client);
                $content = $this->ensureJsonOrThrow($this->extractContent($raw));
                $lastModelText = $content;

                $json = json_decode($content, true);
                if (!is_array($json)) {
                    throw new RuntimeException('Non-JSON from LLM');
                }
                $assert($json);
                return $json;
            } catch (Throwable $e) {
                // Попытка «ремонта»
                try {
                    $client2 = $this->client();
                    $repairSystem = 'Ты — строгий JSON-ремонтник. Верни ТОЛЬКО валидный JSON-объект без Markdown.';
                    $repairBody =
                        "ПОЧИНИ JSON ПОД ТОЧНУЮ СХЕМУ.\n" .
                        "1) Ключи и структура — как в SCHEMA.\n" .
                        "2) Пустые/незаполнимые заполняй по типу ([]/\"\"/null/0).\n" .
                        "3) Никаких лишних ключей.\n\n" .
                        "SCHEMA:\n" . json_encode($repairSkeleton, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n" .
                        "ORIGINAL_MODEL_TEXT:\n" . (is_string($lastModelText) ? $lastModelText : '(none)') . "\n\n" .
                        "ERROR:\n" . $e->getMessage();

                    $client2
                        ->setTemperature(0.0)
                        ->setResponseFormat('json_object')
                        ->query($repairSystem, 'system')
                        ->query($this->jsonGuardInstruction(), 'user')
                        ->query($repairBody, 'user');

                    $raw2 = $this->runWithRetries($client2);
                    $content2 = $this->ensureJsonOrThrow($this->extractContent($raw2));
                    $json2 = json_decode($content2, true);
                    if (!is_array($json2)) {
                        throw new RuntimeException('Repair failed: non-JSON');
                    }
                    $assert($json2);
                    return $json2;
                } catch (Throwable) {
                    // next attempt
                }
            }
        }

        // Последний шаг: форма-каркас
        return $repairSkeleton;
    }

    // ---------------- PUBLIC API ----------------

    /** Строгий chunk_summary */
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

    /** Строгий reducer */
    private function reduceChunksStrict(array $summaries, int $chatId, string $date): array
    {
        $payload = [
            'chat_id' => $chatId,
            'date' => $date,
            'timezone' => $this->timezone,
            'expected_chunk_id' => "merged-{$chatId}-{$date}",
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

    /** Строгий executive */
    private function executiveStrict(array $merged, int $chatId, string $date): array
    {
        $payload = [
            'chat_id' => $chatId,
            'date' => $date,
            'timezone' => $this->timezone,
            'merged' => $merged,
            'limits' => ['list_max' => 7, 'quote_max_words' => 12],
        ];

        $out = $this->llmStrict(
            'executive_report_v6',
            $payload,
            function (array $j) {
                JsonShape::assertExecutive($j);
            },
            $this->skeletonExecutive($chatId, $date)
        );

        // Гарантируем непротиворечие метрик (без «додумывания»):
        if (isset($merged['char_counts']['total'])) {
            $out['char_counts']['total'] = (int)$merged['char_counts']['total'];
        }
        if (isset($merged['tokens_estimate'])) {
            $out['tokens_estimate'] = (int)$merged['tokens_estimate'];
        }

        return $out;
    }

    /** messages → chunks → reducer → executive */
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

    /** Текст → messages → основной pipeline */
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

    /** Дайджест по отчётам (строгий) */
    public function summarizeReports(array $reports, string $date): string
    {
        // Нормализуем: распарсим строки в объекты, если это валидный JSON
        $normalized = [];
        foreach ($reports as $r) {
            if (is_string($r)) {
                $obj = json_decode($r, true);
                $normalized[] = is_array($obj) ? $obj : $r;
            } else {
                $normalized[] = $r;
            }
        }

        $payload = [
            'date' => $date,
            'reports' => $normalized,
            'limits' => ['list_max' => 7],
        ];

        $out = $this->llmStrict(
            'digest_executive_v6',
            $payload,
            function (array $j) {
                JsonShape::assertDigest($j);
            },
            $this->skeletonDigest($date)
        );

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /** Короткий заголовок активной темы (1 строка, ≤80 символов). */
    public function summarizeTopic(string $transcript, string $chatTitle = '', int $chatId = 0): string
    {
        $client = $this->client();
        $system = $this->systemPrompt('topic_summary_v3');

        $payload = [
            'transcript' => $transcript,
            'chat_title' => $chatTitle,
            'chat_id' => (string)$chatId,
        ];

        // Здесь НЕ json_object — промпт просит вернуть строку
        $client
            ->setTemperature(0.2)
            ->query($system, 'system')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

        $raw = $this->runWithRetries($client);
        $out = $this->extractContent($raw);

        // Санитизация
        $out = (string)$out;
        $out = preg_replace('/\s+/u', ' ', $out) ?? $out;
        $out = trim($out);
        if ((str_starts_with($out, '"') && str_ends_with($out, '"')) ||
            (str_starts_with($out, '“') && str_ends_with($out, '”')) ||
            (str_starts_with($out, '«') && str_ends_with($out, '»')) ||
            (str_starts_with($out, '`') && str_ends_with($out, '`'))
        ) {
            $out = mb_substr($out, 1, mb_strlen($out, 'UTF-8') - 2, 'UTF-8');
        }
        $out = rtrim($out, " .。!！?？;；…");
        if (mb_strlen($out, 'UTF-8') > 80) {
            $out = mb_substr($out, 0, 80, 'UTF-8');
        }

        return $out;
    }
}
