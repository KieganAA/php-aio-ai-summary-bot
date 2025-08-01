<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Src\Util\TextUtils;

class TextUtilsTest extends TestCase
{
    public function testCleanTranscriptRemovesSystemMessages(): void
    {
        $raw = "John joined the group\nJane left the group\nBob sent a sticker\nPhoto · 12345\n[foo @ 00:00] hello\n";
        $cleaned = TextUtils::cleanTranscript($raw);
        $this->assertStringNotContainsString('joined the group', $cleaned);
        $this->assertStringNotContainsString('left the group', $cleaned);
        $this->assertStringNotContainsString('sent a sticker', $cleaned);
        $this->assertStringNotContainsString('Photo ·', $cleaned);
        $this->assertStringContainsString('[foo @ 00:00] hello', $cleaned);
    }

    public function testEscapeMarkdownHandlesHyphens(): void
    {
        $input = "Line with hyphen - dash\n- bullet item";
        $expected = "Line with hyphen \\- dash\n- bullet item";
        $this->assertSame($expected, TextUtils::escapeMarkdown($input));
    }
}
