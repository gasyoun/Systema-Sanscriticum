<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\CourseBlock;
use App\Models\Schedule;
use App\Models\User;

/**
 * Строит RFC 5545 VCALENDAR для персонального read-only фида студента
 * (Google Calendar Phase 1 — docs/GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md §3/§7).
 *
 * Источник событий намеренно зеркалит StudentController::calendar() (те же
 * группы, тот же фильтр «карточка живёт до конца занятия»), плюс диапазоны
 * Course/CourseBlock как all-day события — фид не должен расходиться с тем,
 * что студент видит в кабинете.
 */
class IcsFeedBuilder
{
    public function build(User $user): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Systema-Sanscriticum//Calendar Feed//RU',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape(config('app.name').' — расписание'),
        ];

        foreach ($this->scheduleEvents($user) as $schedule) {
            array_push($lines, ...$this->scheduleToVevent($schedule));
        }

        foreach ($this->courseBlockEvents($user) as $block) {
            array_push($lines, ...$this->courseBlockToVevent($block));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([$this, 'fold'], $lines))."\r\n";
    }

    /** @return \Illuminate\Support\Collection<int, Schedule> */
    private function scheduleEvents(User $user)
    {
        $groupIds = $user->groups->pluck('id');

        return Schedule::with(['course', 'group'])
            ->where(function ($query) use ($groupIds) {
                $query->whereIn('group_id', $groupIds)
                    ->orWhereNull('group_id');
            })
            ->where(function ($query) {
                $query->where('end', '>=', now())
                    ->orWhere(function ($q) {
                        $q->whereNull('end')
                            ->where('start', '>=', now()->subHours(Schedule::DEFAULT_DURATION_HOURS));
                    });
            })
            ->orderBy('start', 'asc')
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, CourseBlock> */
    private function courseBlockEvents(User $user)
    {
        $courseIds = $user->groups->flatMap(fn ($group) => $group->courses->pluck('id'))
            ->merge($user->courses->pluck('id'))
            ->unique()
            ->values();

        return CourseBlock::whereIn('course_id', $courseIds)
            ->whereNotNull('starts_at')
            ->with('course')
            ->orderBy('starts_at')
            ->get();
    }

    private function scheduleToVevent(Schedule $schedule): array
    {
        $end = $schedule->end ?? $schedule->start->copy()->addHours(Schedule::DEFAULT_DURATION_HOURS);

        $descriptionParts = [];
        if ($schedule->zoom_join_url) {
            $descriptionParts[] = 'Zoom: '.$schedule->zoom_join_url;
        } elseif ($schedule->link) {
            $descriptionParts[] = 'Ссылка: '.$schedule->link;
        }

        $lines = [
            'BEGIN:VEVENT',
            'UID:schedule-'.$schedule->id.'@'.$this->uidHost(),
            'DTSTAMP:'.$this->utcStamp(now()),
            'DTSTART:'.$this->utcStamp($schedule->start),
            'DTEND:'.$this->utcStamp($end),
            'SUMMARY:'.$this->escape($schedule->title ?: ($schedule->course->title ?? 'Занятие')),
        ];

        if ($descriptionParts !== []) {
            $lines[] = 'DESCRIPTION:'.$this->escape(implode('\n', $descriptionParts));
        }
        if ($schedule->zoom_join_url || $schedule->link) {
            $lines[] = 'LOCATION:'.$this->escape($schedule->zoom_join_url ?: $schedule->link);
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private function courseBlockToVevent(CourseBlock $block): array
    {
        $start = $block->starts_at->copy()->startOfDay();
        // DTEND для all-day событий в RFC 5545 эксклюзивен — берём день ПОСЛЕ
        // последнего дня блока (ends_at, если задан, иначе тот же день, что start).
        $end = ($block->ends_at ?? $block->starts_at)->copy()->startOfDay()->addDay();

        return [
            'BEGIN:VEVENT',
            'UID:course-block-'.$block->id.'@'.$this->uidHost(),
            'DTSTAMP:'.$this->utcStamp(now()),
            'DTSTART;VALUE=DATE:'.$start->format('Ymd'),
            'DTEND;VALUE=DATE:'.$end->format('Ymd'),
            'SUMMARY:'.$this->escape(($block->course->title ?? 'Курс').' — '.($block->title ?: 'блок '.$block->number)),
            'TRANSP:TRANSPARENT',
            'END:VEVENT',
        ];
    }

    private function utcStamp(\DateTimeInterface $dt): string
    {
        return \Illuminate\Support\Carbon::instance($dt)->clone()->utc()->format('Ymd\THis\Z');
    }

    private function uidHost(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $host ?: 'systema-sanscriticum.local';
    }

    /** Экранирование текстовых полей per RFC 5545 §3.3.11. */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', ',', ';', "\r\n", "\n"],
            ['\\\\', '\\,', '\\;', '\\n', '\\n'],
            $value
        );
    }

    /** Фолдинг строк на 75 октетах с продолжением через "\r\n " per RFC 5545 §3.1. */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $chunks = mb_str_split($line, 1);
        $folded = '';
        $lineLength = 0;

        foreach ($chunks as $chunk) {
            $chunkLength = strlen($chunk);
            if ($lineLength + $chunkLength > 75) {
                $folded .= "\r\n ";
                $lineLength = 1;
            }
            $folded .= $chunk;
            $lineLength += $chunkLength;
        }

        return $folded;
    }
}
