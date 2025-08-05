<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Src\Util\TextUtils;

class TextUtilsTest extends TestCase
{
    public function testCleanTranscriptRemovesSystemMessages(): void
    {
        $raw     = "John joined the group\nJane left the group\nBob sent a sticker\nPhoto · 12345\n[foo @ 00:00] hello\n";
        $cleaned = TextUtils::cleanTranscript($raw);

        $this->assertStringNotContainsString('joined the group', $cleaned);
        $this->assertStringNotContainsString('left the group', $cleaned);
        $this->assertStringNotContainsString('sent a sticker', $cleaned);
        $this->assertStringNotContainsString('Photo ·', $cleaned);
        $this->assertSame('[foo @ 00:00] hello', $cleaned);
    }

    public function testBuildTranscriptNoTrailingNewline(): void
    {
        date_default_timezone_set('UTC');
        $messages = [
            ['from_user' => 'Alice', 'message_date' => 0, 'text' => 'hi'],
            ['from_user' => 'Bob', 'message_date' => 60, 'text' => 'hello'],
        ];

        $expected = "[Alice @ 00:00] hi\n[Bob @ 00:01] hello";
        $this->assertSame($expected, TextUtils::buildTranscript($messages));
    }

    public function testEscapeMarkdownHandlesHyphens(): void
    {
        $input = "Line with hyphen - dash\n- bullet item";
        $expected = "Line with hyphen \\- dash\n\\- bullet item";
        $this->assertSame($expected, TextUtils::escapeMarkdown($input));
    }

    public function testEscapeMarkdownDoesNotUnescapeDanglingHyphen(): void
    {
        $input = '- ';
        $expected = '\- ';
        $this->assertSame($expected, TextUtils::escapeMarkdown($input));
    }

    public function testEscapeMarkdownEscapesDots(): void
    {
        $input = "Sentence one. Sentence two.";
        $expected = "Sentence one\\. Sentence two\\.";
        $this->assertSame($expected, TextUtils::escapeMarkdown($input));
    }

    public function testEscapeMarkdownPreservesExistingEscapes(): void
    {
        $input = '\\_already escaped\\_ and *bold*';
        $expected = '\\_already escaped\\_ and \\*bold\\*';
        $this->assertSame($expected, TextUtils::escapeMarkdown($input));
    }

    public function testEscapeMarkdownEscapesParentheses(): void
    {
        $input = 'Example (test)';
        $expected = 'Example \\(test\\)';
        $this->assertSame($expected, TextUtils::escapeMarkdown($input));
    }
}
