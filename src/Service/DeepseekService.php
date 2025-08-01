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
        // Enable streaming and disable timeouts so long requests do not idle out.
        $http = new HttpClient([
            'base_uri' => 'https://api.deepseek.com/v3',
            'timeout'  => 0,
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
    private function chunkTranscript(string $transcript): array
    {
        $messages = explode("\n", trim($transcript));
        $chunks = [];
        $current = '';
        foreach ($messages as $msg) {
            $t = TokenCounter::count($msg);
            if (TokenCounter::count($current) + $t > 3000) {
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
     * Run the heavy global pass using the standard DailyChat prompt.
     */
    private function finalSummary(string $input, string $chatTitle, int $chatId, string $date): string
    {
        $client = $this->client();
        $prompt = <<<PROMPT
### System
You are "DailyChat-Reporter-v1".
Your sole task is to turn a full-day Telegram transcript into an **identical, repeatable Markdown report** that ops can skim in
<2 min.
You must output **exactly the 10 numbered sections shown below** (even if some are empty ⇒ write "None").
Never add, delete, reorder, or rename sections.
Keep every bullet ≤ 20 words, use past tense, omit pleasantries, redact sensitive data as "***".

### Input
CHAT_TITLE: **{$chatTitle}**
CHAT_ID: **{$chatId}**
DATE: **{$date}** (Europe/Berlin)
TRANSCRIPT:
{$input}

### Output format (copy verbatim)

# Daily Chat Report

Chat: {CHAT_TITLE} (ID {CHAT_ID})
Date: {YYYY-MM-DD}

1. 👥  Participants

   * {nameA} — role
   * {nameB} — role

2. 🗺️  High-Level Overview

   * …

3. 💬  Main Topics Discussed

   * …

4. 🛠️  Issues Raised (with status)

   * …

5. ✅  Issues Resolved Today

   * …

6. ⏳  Open Tasks / Blockers

   * …

7. 📌  Action Items for Support Team

   * [ ] owner • due-date • task

8. 📌  Action Items for Client

   * [ ] owner • due-date • task

9. 🤔  Questions Awaiting Reply

   * …

10. 🔮  Next Steps / Follow-ups

    * …

### Rules (obligatory)
* Always produce **all 10 sections** in the order above.
* Bullets start with "- ". Sub-bullets are indented two spaces.
* If a section has zero content, write "None".
* Do **not** generate any text outside the fenced block.
* Language: write in succinct business English, preserve domain jargon.
* Strip greetings, signatures, stickers, images; summarise only meaning.
* Token budget ≈ 1 000; truncate low-value chatter if needed, but never omit useful decisions, tasks, or blockers.
PROMPT;

        $client->query($prompt, 'system');
        $raw = $client->run();
        $data = json_decode($raw, true);
        return trim($data['choices'][0]['message']['content'] ?? $raw);
    }

    public function summarize(
        string $transcript,
        string $chatTitle = '',
        int $chatId = 0,
        ?string $date = null
    ): string {
        $date ??= date('Y-m-d');

        $chunks = $this->chunkTranscript($transcript);
        if (count($chunks) === 1) {
            return $this->finalSummary($transcript, $chatTitle, $chatId, $date);
        }

        $summaries = [];
        foreach ($chunks as $chunk) {
            $summaries[] = $this->summarizeChunk($chunk);
        }

        $summaryInput = implode("\n", $summaries);
        return $this->finalSummary($summaryInput, $chatTitle, $chatId, $date);
    }
}
