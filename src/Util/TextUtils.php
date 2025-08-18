<?php
declare(strict_types=1);

namespace Src\Util;

final class TextUtils
{
    /**
     * Build a simple transcript line-per-message.
     * - Safeguards missing fields
     * - Collapses internal newlines in message text
     */
    public static function buildTranscript(array $messages): string
    {
        $lines = [];
        foreach ($messages as $m) {
            $user = (string)($m['from_user'] ?? 'unknown');
            $ts = (int)($m['message_date'] ?? time());
            $t = date('H:i', $ts);
            $text = (string)($m['text'] ?? '');

            // Replace hard newlines inside a message to keep one line per entry
            $text = preg_replace('/\s*\R\s*/u', ' ⏎ ', trim($text)) ?? trim($text);

            $lines[] = "[{$user} @ {$t}] {$text}";
        }

        return implode("\n", $lines);
    }

    /**
     * Remove noise/service messages commonly found in chats and collapse blank lines.
     * Covers EN/RU patterns (join/leave/pinned/sticker/photo/etc.).
     */
    public static function cleanTranscript(string $rawTranscript): string
    {
        $patterns = [
            // English service messages
            '/^\w+\s+joined the group.*$/mu',
            '/^\w+\s+left the group.*$/mu',
            '/^\w+\s+pinned a message.*$/mu',
            '/^\w+\s+changed (the )?group (photo|title).*$/mu',
            '/^Photo\s*·\s*.*$/mu',
            '/^Video\s*·\s*.*$/mu',
            '/^Sticker\s*·\s*.*$/mu',
            '/^GIF\s*·\s*.*$/mu',
            '/^\w+\s+sent a sticker.*$/mu',
            '/^\w+\s+invited\s+\w+.*$/mu',

            // Russian service messages (generic forms)
            '/^\w+(\s\w+)*\s+вош[её]л[а]? в групп[уе].*$/mu',
            '/^\w+(\s\w+)*\s+покинул[а]? групп[уе].*$/mu',
            '/^\w+(\s\w+)*\s+закрепил[а]? сообщение.*$/mu',
            '/^\w+(\s\w+)*\s+изменил[а]? (фото|название) групп[уы].*$/mu',
            '/^\w+(\s\w+)*\s+отправил[а]? стикер.*$/mu',
            '/^Фото\s*·\s*.*$/mu',
            '/^Видео\s*·\s*.*$/mu',
            '/^Стикер\s*·\s*.*$/mu',
        ];

        $result = preg_replace($patterns, '', $rawTranscript);
        if ($result === null) {
            return trim($rawTranscript);
        }

        // Collapse 2+ blank lines into one
        $collapsed = preg_replace("/\n{2,}/", "\n", $result) ?? $result;

        return trim($collapsed);
    }

    /**
     * Build + clean combo.
     */
    public static function buildCleanTranscript(array $messages): string
    {
        return self::cleanTranscript(self::buildTranscript($messages));
    }

    /**
     * Escape a string for Telegram MarkdownV2.
     * Based on telegramify-markdown approach.
     */
    public static function escapeMarkdown(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Convert HTML entities first
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Escape all MarkdownV2 special chars
        $pattern = '/([\\_\*\[\]\(\)~`>\#\+\-\=\|\{\}\.\!])/u';
        $escaped = preg_replace($pattern, '\\\\$1', $text);

        // Remove double-escaping if any
        $escaped = preg_replace('/\\\\\\\\([\\_\*\[\]\(\)~`>\#\+\-\=\|\{\}\.\!])/u', '\\\\$1', $escaped);

        return $escaped ?? $text;
    }

    /**
     * Split long text into Telegram-safe chunks.
     * Strategy: paragraphs -> lines -> hard cut. Tries not to end with a lone backslash.
     *
     * @return string[] chunks (<= $budget each)
     */
    public static function splitForTelegram(string $text, int $budget = 3900): array
    {
        $text = (string)$text;
        if (mb_strlen($text, 'UTF-8') <= $budget) {
            return [$text];
        }

        // First split by blank lines (paragraphs)
        $paras = preg_split("/\n{2,}/u", $text) ?: [$text];
        $out = [];
        $buf = '';

        $flush = static function () use (&$buf, &$out) {
            if ($buf === '') {
                return;
            }
            // Avoid trailing lone backslash that breaks MarkdownV2
            while (mb_substr($buf, -1, 1, 'UTF-8') === '\\') {
                $buf = mb_substr($buf, 0, -1, 'UTF-8');
            }
            $out[] = $buf;
            $buf = '';
        };

        foreach ($paras as $p) {
            $candidate = $buf === '' ? $p : ($buf . "\n\n" . $p);
            if (mb_strlen($candidate, 'UTF-8') <= $budget) {
                $buf = $candidate;
                continue;
            }
            // flush buffer and try to split paragraph by lines
            $flush();

            if (mb_strlen($p, 'UTF-8') <= $budget) {
                $buf = $p;
                continue;
            }

            $lines = preg_split("/\n/u", $p) ?: [$p];
            $chunk = '';
            foreach ($lines as $line) {
                $cand = $chunk === '' ? $line : ($chunk . "\n" . $line);
                if (mb_strlen($cand, 'UTF-8') <= $budget) {
                    $chunk = $cand;
                } else {
                    // emit previous chunk
                    while (mb_substr($chunk, -1, 1, 'UTF-8') === '\\') {
                        $chunk = mb_substr($chunk, 0, -1, 'UTF-8');
                    }
                    if ($chunk !== '') {
                        $out[] = $chunk;
                    }
                    // if single line is still too long: hard split
                    if (mb_strlen($line, 'UTF-8') > $budget) {
                        $start = 0;
                        $len = mb_strlen($line, 'UTF-8');
                        while ($start < $len) {
                            $part = mb_substr($line, $start, $budget, 'UTF-8');
                            while (mb_substr($part, -1, 1, 'UTF-8') === '\\') {
                                $part = mb_substr($part, 0, -1, 'UTF-8');
                            }
                            $out[] = $part;
                            $start += mb_strlen($part, 'UTF-8');
                        }
                        $chunk = '';
                    } else {
                        $chunk = $line;
                    }
                }
            }
            if ($chunk !== '') {
                $buf = $chunk;
            }
        }
        $flush();

        return $out;
    }

    /**
     * Convert MarkdownV2 text to a readable plain text (for Slack/Notion or fallback).
     */
    public static function toPlainText(string $markdownV2): string
    {
        $text = (string)$markdownV2;

        // Unescape backslash-escaped specials
        $text = str_replace(
            ['\\_', '\\*', '\\[', '\\]', '\\(', '\\)', '\\~', '\\`', '\\>', '\\#', '\\+', '\\-', '\\=', '\\|', '\\{', '\\}', '\\.', '\\!'],
            ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'],
            $text
        );

        // Strip formatting symbols
        $text = str_replace(['*', '_', '`'], '', $text);

        // Normalize whitespace
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Normalize usernames for display in Telegram messages.
     */
    public static function sanitizeUsername(string $raw): string
    {
        $u = ltrim(trim($raw), '@');
        return $u;
    }
}
