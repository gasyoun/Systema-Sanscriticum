<?php

declare(strict_types=1);

namespace App\Services\TelegramBusiness;

use RuntimeException;

/** Validate an off-server transcript and shift its cues into one Story part. */
final class TelegramStorySrt
{
    /** @return list<array{start: int, end: int, text: string}> milliseconds */
    public static function cues(string $srt): array
    {
        if (strlen($srt) > 200_000) {
            throw new RuntimeException('Story subtitle draft is too large.');
        }
        $blocks = preg_split('/\R\s*\R/u', trim($srt)) ?: [];
        $cues = [];
        foreach ($blocks as $block) {
            $lines = preg_split('/\R/u', trim($block)) ?: [];
            if (isset($lines[0]) && ctype_digit(trim($lines[0]))) {
                array_shift($lines);
            }
            $timing = array_shift($lines);
            if (! is_string($timing) || preg_match(
                '/\A(\d{2}):(\d{2}):(\d{2})[,.](\d{3})\s*-->\s*(\d{2}):(\d{2}):(\d{2})[,.](\d{3})\z/',
                trim($timing), $match,
            ) !== 1) {
                throw new RuntimeException('Story subtitle draft contains an invalid SRT timestamp.');
            }
            $start = self::milliseconds($match[1], $match[2], $match[3], $match[4]);
            $end = self::milliseconds($match[5], $match[6], $match[7], $match[8]);
            $text = trim(strip_tags(implode("\n", $lines)));
            if ($end <= $start || $text === '' || mb_strlen($text) > 500) {
                throw new RuntimeException('Story subtitle draft contains an invalid cue.');
            }
            $cues[] = ['start' => $start, 'end' => $end, 'text' => $text];
            if (count($cues) > 2000) {
                throw new RuntimeException('Story subtitle draft contains too many cues.');
            }
        }
        if ($cues === []) {
            throw new RuntimeException('Story subtitle draft has no cues.');
        }

        return $cues;
    }

    public static function segment(string $srt, int $offsetSeconds, float $lengthSeconds): string
    {
        $start = $offsetSeconds * 1000;
        $end = $start + (int) round($lengthSeconds * 1000);
        $blocks = [];
        foreach (self::cues($srt) as $cue) {
            if ($cue['end'] <= $start || $cue['start'] >= $end) {
                continue;
            }
            $blocks[] = (count($blocks) + 1)."\n"
                .self::timestamp(max(0, $cue['start'] - $start)).' --> '
                .self::timestamp(min($end - $start, $cue['end'] - $start))."\n"
                .$cue['text'];
        }

        return $blocks === [] ? '' : implode("\n\n", $blocks)."\n";
    }

    private static function milliseconds(string $hours, string $minutes, string $seconds, string $millis): int
    {
        if ((int) $minutes >= 60 || (int) $seconds >= 60) {
            throw new RuntimeException('Story subtitle timestamp is out of range.');
        }

        return (((int) $hours * 60 + (int) $minutes) * 60 + (int) $seconds) * 1000 + (int) $millis;
    }

    private static function timestamp(int $milliseconds): string
    {
        $hours = intdiv($milliseconds, 3_600_000);
        $minutes = intdiv($milliseconds % 3_600_000, 60_000);
        $seconds = intdiv($milliseconds % 60_000, 1000);

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $seconds, $milliseconds % 1000);
    }
}
