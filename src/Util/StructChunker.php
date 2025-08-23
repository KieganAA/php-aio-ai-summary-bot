<?php
declare(strict_types=1);

namespace Src\Util;

final class StructChunker
{
    /**
     * @param array<int,array{id?:int, message_id?:int, message_date?:int, from_user?:string, reply_to?:int, text?:string}> $messages
     * @return array<int,array> Chunks (each: array of input messages)
     */
    public static function chunkByStructure(array $messages, int $gapMinutes, string $timezone): array
    {
        usort($messages, fn($a, $b) => (int)($a['message_date'] ?? 0) <=> (int)($b['message_date'] ?? 0));
        $chunks = [];
        $cur = [];
        $lastTs = null;
        $actors = [];
        foreach ($messages as $m) {
            $ts = isset($m['message_date']) ? (int)$m['message_date'] : null;
            $gapBreak = $lastTs && $ts ? (($ts - $lastTs) > $gapMinutes * 60) : false;
            $sameThread = !empty($cur) && isset($m['reply_to']) && self::inThread($cur, (int)$m['reply_to']);
            $sameActor = !empty($cur) && isset($m['from_user']) && isset($actors[$m['from_user']]);
            if ($gapBreak || (!empty($cur) && !$sameThread && !$sameActor)) {
                $chunks[] = $cur;
                $cur = [];
                $actors = [];
            }
            $cur[] = $m;
            if (!empty($m['from_user'])) {
                $actors[$m['from_user']] = true;
            }
            $lastTs = $ts ?? $lastTs;
        }
        if ($cur) {
            $chunks[] = $cur;
        }
        return self::mergeAdjacentsByActors($chunks);
    }

    private static function inThread(array $cur, int $replyTo): bool
    {
        foreach ($cur as $x) {
            if (($x['message_id'] ?? $x['id'] ?? -1) === $replyTo) return true;
        }
        return false;
    }

    private static function mergeAdjacentsByActors(array $chunks): array
    {
        $out = [];
        foreach ($chunks as $chunk) {
            if (!$out) {
                $out[] = $chunk;
                continue;
            }
            $prev = $out[count($out) - 1];
            $aPrev = array_values(array_unique(array_filter(array_map(fn($m) => $m['from_user'] ?? '', $prev))));
            $aCur = array_values(array_unique(array_filter(array_map(fn($m) => $m['from_user'] ?? '', $chunk))));
            $over = count(array_intersect($aPrev, $aCur));
            $den = max(1, min(count($aPrev), count($aCur)));
            if ($over / $den >= 0.5) {
                $out[count($out) - 1] = array_merge($prev, $chunk);
            } else {
                $out[] = $chunk;
            }
        }
        return $out;
    }
}
