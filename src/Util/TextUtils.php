<?php
declare(strict_types=1);

namespace Src\Util;

class TextUtils
{
    public static function buildTranscript(array $messages): string
    {
        $lines = [];
        foreach ($messages as $m) {
            $t       = date('H:i', $m['message_date']);
            $lines[] = "[{$m['from_user']} @ {$t}] {$m['text']}";
        }

        return implode("\n", $lines);
    }

    public static function cleanTranscript(string $rawTranscript): string
    {
        $result = preg_replace(
            [
                '/^\\w+ joined the group.*$/m',
                '/^\\w+ left the group.*$/m',
                '/^\\w+ sent a sticker.*$/m',
                '/^Photo · .*$/m'
            ],
            '',
            $rawTranscript
        );

        if ($result === null) {
            return trim($rawTranscript);
        }

        $collapsed = preg_replace("/\n{2,}/", "\n", $result) ?? $result;

        return trim($collapsed);
    }

    /**
     * Build a transcript from messages and apply cleanup rules.
     */
    public static function buildCleanTranscript(array $messages): string
    {
        return self::cleanTranscript(self::buildTranscript($messages));
    }

    /**
     * Escape a string for safe use with Telegram MarkdownV2.
     *
     * Ported from the telegramify-markdown project to ensure proper
     * escaping without double-escaping already escaped characters.
     * @see https://github.com/sudoskys/telegramify-markdown
     */
    public static function escapeMarkdown(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Convert HTML entities back to characters before escaping
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // First pass: escape all special MarkdownV2 characters
        $pattern = '/([\\_\*\[\]\(\)~`>\#\+\-\=\|\{\}\.\!])/';
        $escaped = preg_replace($pattern, "\\\\$1", $text);

        // Second pass: remove double escaping
        return preg_replace('/\\\\\\\\([\\_\*\[\]\(\)~`>\#\+\-\=\|\{\}\.\!])/', "\\\\$1", $escaped);
    }
}
