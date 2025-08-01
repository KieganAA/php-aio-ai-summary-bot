<?php
declare(strict_types=1);

namespace Src\Util;

class TextUtils
{
    public static function buildTranscript(array $messages): string
    {
        $transcript = '';
        foreach ($messages as $m) {
            $t = date('H:i', $m['message_date']);
            $transcript .= "[{$m['from_user']} @ {$t}] {$m['text']}\n";
        }
        return $transcript;
    }

    public static function cleanTranscript(string $rawTranscript): string
    {
        return preg_replace(
            [
                '/^\\w+ joined the group.*$/m',
                '/^\\w+ left the group.*$/m',
                '/^\\w+ sent a sticker.*$/m',
                '/^Photo · .*$/m'
            ],
            '',
            $rawTranscript
        );
    }

    public static function escapeMarkdown(string $text): string
    {
        $special = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        foreach ($special as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        // allow bullet lists: unescape hyphen at line start followed by space
        $text = preg_replace('/(^|\n)\\\\-\s/', '$1- ', $text);
        return $text;
    }
}
