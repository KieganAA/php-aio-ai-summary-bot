<?php
declare(strict_types=1);

namespace Src\Service;

use DeepSeek\DeepSeekClient;

class DeepseekService
{
    private DeepSeekClient $client;

    public function __construct(string $apiKey)
    {
        $this->client = DeepSeekClient::build($apiKey);
    }

    public function summarize(
        string $transcript,
        string $chatTitle = '',
        int $chatId = 0,
        ?string $date = null
    ): string {
        $date ??= date('Y-m-d');

        $prompt = <<<PROMPT
### System
You are "DailyChat-Reporter-v1".
Your sole task is to turn a full-day Telegram transcript into an **identical, repeatable Markdown report** that ops can skim in <2 min.
You must output **exactly the 10 numbered sections shown below** (even if some are empty ⇒ write "None").
Never add, delete, reorder, or rename sections.
Keep every bullet ≤ 20 words, use past tense, omit pleasantries, redact sensitive data as "***".

### Input
CHAT_TITLE: **{$chatTitle}**
CHAT_ID: **{$chatId}**
DATE: **{$date}** (Europe/Berlin)
TRANSCRIPT:
{$transcript}

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

        $this->client->query($prompt, 'system');
        $raw = $this->client->run();
        $data = json_decode($raw, true);
        if (isset($data['choices'][0]['message']['content'])) {
            return trim($data['choices'][0]['message']['content']);
        }
        return $raw;
    }
}
