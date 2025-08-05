<?php
declare(strict_types=1);

namespace Src\Service;

use DeepSeek\DeepSeekClient;
use GuzzleHttp\Client as HttpClient;
use Src\Util\TextUtils;
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
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new \InvalidArgumentException('API key must not be empty');
        }

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
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $raw = $client->run();
            } catch (\Throwable $e) {
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
                throw new \RuntimeException('Cloudflare SSL handshake failed (error 525)');
            }
            usleep((int)(250_000 * (2 ** $attempt)));
        }

        throw new \RuntimeException('Failed to receive valid response from DeepSeek');
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
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^data:\\s*(.+)$/', $line, $m)) {
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
Вы — ChatChunk-Summarizer-v1.
Возвращайте ТОЛЬКО СТРОГИЙ JSON (без текста). Цель: зафиксировать, что произошло в этом фрагменте чата, чтобы потом объединить.

Правила:
- Язык: русский. Стиль: деловой, краткий, прошедшее время.
- Игнорируй приветствия, стикеры, входы/выходы, изображения.
- Каждая запись ≤ 40 слов.
- Если для поля нет данных, выводи [] или "" (не null).
- Не выдумывай факты; используй "unknown", когда данных нет.
- Время: ISO-8601 местное время для DATE и TIMEZONE, если указано явно, иначе пропускайте время.
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
### Система
Вы — "ChatSummariser-v2".
Вам нужно кратко суммировать отрывок чата Telegram в компактный JSON-объект.
Не добавляйте текст вне JSON. Язык: только русский. До 40 слов на пункт.

### Участники
Наши сотрудники: {$our}
Сотрудники клиента: {$clients}

### Вход
CHAT_TITLE: {$chatTitle}
DATE: {$date}
TRANSCRIPT:
{$input}

### Выход (только JSON)
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
            $sectionTitle = TextUtils::escapeMarkdown($title);
            $lines[]      = "*{$sectionTitle}*";
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
            $lines[]      = "*{$sectionTitle}*";
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
        // $prompt = "Summarize in no more than 30 words what the chat messages are about:\n" . $transcript;
        $prompt = "Кратко суммируй на русском языке, используя не больше 30 слов - о чем были сообщения:\n" . $transcript;
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

    public function summarizeReports(array $reports, string $date): string
    {
        $client = $this->client();
        $system = <<<SYS
Вы — ChatC-LevelDigest-v1.
Цель: выдать КОМПАКТНЫЙ JSON-отчёт о состоянии клиентских чатов (для топ-менеджеров).
• Пиши ТОЛЬКО JSON, никаких пояснений.
• Язык: русский. Каждый элемент ≤ 20 слов.
• Не пытайся определять ответственных или статус задач.
• Игнорируй приветствия, стикеры, «спасибо».
• Используй только информацию из chat_summaries, не выдумывай и не предлагай решений.
• Если данных нет — верни [] или "".
SYS;

        $payload = [
            'date' => $date,
            'chat_summaries' => $reports,
        ];

        $client
            ->setTemperature(0.2)
            ->setResponseFormat('json_object')
            ->query($system, 'system')
            ->query(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user');

        $raw = $this->runWithRetries($client);
        return trim($this->extractContent($raw));
    }
}
