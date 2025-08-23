<?php
declare(strict_types=1);

namespace Src\Util;

use RuntimeException;

final class JsonShape
{
    public static function assertChunkSummary(array $d): void
    {
        self::must($d, [
            'chunk_id', 'date', 'timezone', 'participants', 'highlights', 'issues', 'decisions',
            'actions', 'blockers', 'questions', 'timeline', 'evidence_quotes', 'char_counts', 'tokens_estimate'
        ]);
    }

    public static function assertExecutive(array $d): void
    {
        self::must($d, [
            'chat_id', 'date', 'verdict', 'health_score', 'client_mood', 'summary', 'incidents', 'warnings',
            'decisions', 'open_questions', 'sla', 'timeline', 'notable_quotes', 'quality_flags', 'trimming_report',
            'char_counts', 'tokens_estimate'
        ]);
    }

    private static function must(array $d, array $keys): void
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $d)) {
                throw new RuntimeException('Missing key: ' . $k);
            }
        }
    }
}
