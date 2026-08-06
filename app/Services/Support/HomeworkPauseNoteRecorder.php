<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * B: inbound student text → append a dated «пауза по ДЗ» line on users.note.
 *
 * Matches only when homework cue AND life/pause cue both fire (avoids false
 * positives like «догоню в другую группу»). Idempotent per user per calendar day
 * via marker [auto-hw-pause:YYYY-MM-DD]. Never touches HomeworkSubmission status.
 */
class HomeworkPauseNoteRecorder
{
    public const MARKER_PREFIX = '[auto-hw-pause:';

    /**
     * @return bool true when a new note line was appended
     */
    public function recordIfMatches(User $user, string $text, string $source = 'unknown'): bool
    {
        if (! config('support.homework_pause_note.enabled', true)) {
            return false;
        }

        $text = trim($text);
        if ($text === '' || ! $this->matches($text)) {
            return false;
        }

        $day = Carbon::now(config('app.timezone', 'UTC'))->toDateString();
        $marker = self::MARKER_PREFIX.$day.']';
        $existing = (string) ($user->note ?? '');

        if (str_contains($existing, $marker)) {
            return false;
        }

        $quoteMax = max(40, (int) config('support.homework_pause_note.quote_max', 120));
        $quote = $this->clipQuote($text, $quoteMax);
        $line = sprintf(
            '%s: пауза по ДЗ (auto). Не давить, check-in ~+14д. Источник: %s. %s Цитата: «%s»',
            $day,
            $this->safeSource($source),
            $marker,
            $quote,
        );

        $user->note = $existing === '' ? $line : rtrim($existing)."\n".$line;
        $user->save();

        Log::info('homework_pause_note.appended', [
            'user_id' => $user->id,
            'source' => $source,
            'day' => $day,
        ]);

        return true;
    }

    public function matches(string $text): bool
    {
        $haystack = mb_strtolower(trim($text));
        if ($haystack === '') {
            return false;
        }

        $homework = $this->containsAny($haystack, $this->list('homework_cues', [
            'домашк',
            'домашн',
            ' дз',
            'дз ',
            'дз,',
            'дз.',
            'задан',
        ]));

        $life = $this->containsAny($haystack, $this->list('life_cues', [
            'больничн',
            'выбило из колеи',
            'застой',
            'не успеваю',
            'на паузе',
            'пауза по',
            'отстаю',
            'отстала',
            'отстал ',
            'с ребенком',
            'с ребёнком',
            'с детьми',
            'форс-мажор',
            'форс мажор',
        ]));

        return $homework && $life;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            $n = mb_strtolower(trim($needle));
            if ($n !== '' && mb_strpos($haystack, $n) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private function list(string $key, array $default): array
    {
        $raw = config('support.homework_pause_note.'.$key, $default);
        if (! is_array($raw)) {
            return $default;
        }

        return array_values(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            $raw,
        ), static fn (string $v): bool => $v !== ''));
    }

    private function clipQuote(string $text, int $max): string
    {
        $oneLine = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $oneLine = trim($oneLine);
        if (mb_strlen($oneLine) <= $max) {
            return $oneLine;
        }

        return rtrim(mb_substr($oneLine, 0, $max - 1)).'…';
    }

    private function safeSource(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return 'unknown';
        }

        return mb_substr(preg_replace('#[^\w.\-:/]+#u', '_', $source) ?? $source, 0, 40);
    }
}
