<?php
declare(strict_types=1);

namespace Src\Util;

/**
 * Lightweight token counter.
 *
 * We do not need exact token counts, only a rough estimate to
 * split long transcripts into smaller chunks. We approximate
 * the number of tokens by dividing the character length by four,
 * which roughly matches the average token size for English text.
 */
class TokenCounter
{
    public static function count(string $text): int
    {
        // mb_strlen handles multibyte characters safely.
        return (int) ceil(mb_strlen($text, 'UTF-8') / 4);
    }
}
