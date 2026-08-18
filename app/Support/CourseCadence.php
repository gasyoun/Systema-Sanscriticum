<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ритм живого курса, выведенный ИЗ расписания (`schedules`), а не из ручных полей.
 *
 * Зачем: витрина показывала бейдж «Идет сейчас» (ручной enum `courses.format`)
 * и «N ч» (ручное `courses.hours_count`), но ни дня недели, ни времени, ни того,
 * что курс уже на 14-м занятии из 16. Посетитель видел «идёт сейчас» и не мог
 * узнать, когда именно идёт и сколько осталось.
 *
 * Класс НИЧЕГО не выдумывает: нет занятий в календаре — все геттеры пустые,
 * и шаблон просто не рисует строку (тот же принцип, что в StorefrontEvent).
 */
final class CourseCadence
{
    /** Короткие русские дни недели по ISO-номеру (1 = понедельник). */
    private const WEEKDAYS_SHORT = [
        1 => 'пн',
        2 => 'вт',
        3 => 'ср',
        4 => 'чт',
        5 => 'пт',
        6 => 'сб',
        7 => 'вс',
    ];

    /** Родительный падеж «по <дням>» для одного повторяющегося дня. */
    private const WEEKDAYS_PLURAL = [
        1 => 'понедельникам',
        2 => 'вторникам',
        3 => 'средам',
        4 => 'четвергам',
        5 => 'пятницам',
        6 => 'субботам',
        7 => 'воскресеньям',
    ];

    /**
     * Сколько занятий подряд должны попасть в один слот (день + время), чтобы
     * считать его штатным. У «Бхагавадгиты 2ч 2026» 13 занятий во вторник 11:00
     * и 3 разовых сдвига на 11:30 — штатный слот именно 11:00.
     */
    private const SLOT_MAJORITY = 0.5;

    /** @param  Collection<int, Schedule>  $sessions */
    private function __construct(
        private readonly Collection $sessions,
        private readonly Carbon $now,
    ) {}

    public static function for(Course $course, ?Carbon $now = null): self
    {
        return self::forMany(collect([$course]), $now)[$course->id]
            ?? new self(collect(), $now ?? now());
    }

    /**
     * Ритм сразу для набора курсов — ДВА запроса на весь каталог, а не по два
     * на карточку. В каталоге ~90 карточек, поштучный CourseCadence::for()
     * означал бы 180 запросов на рендер.
     *
     * @param  Collection<int, Course>  $courses
     * @return array<int, self>
     */
    public static function forMany(Collection $courses, ?Carbon $now = null): array
    {
        $now ??= now();
        $courseIds = $courses->pluck('id')->filter()->values();

        if ($courseIds->isEmpty()) {
            return [];
        }

        // Занятие привязано к курсу напрямую (schedules.course_id) либо через
        // группу-поток (schedules.group_id) — учитываем оба пути, как
        // Course::upcomingSchedules().
        $groupsByCourse = DB::table('course_group')
            ->whereIn('course_id', $courseIds)
            ->get(['course_id', 'group_id'])
            ->groupBy('course_id')
            ->map(fn ($rows) => $rows->pluck('group_id')->filter()->unique()->values()->all())
            ->all();

        $groupIds = collect($groupsByCourse)->flatten()->unique()->values();

        $sessions = Schedule::query()
            ->where(function ($q) use ($courseIds, $groupIds) {
                $q->whereIn('course_id', $courseIds);

                if ($groupIds->isNotEmpty()) {
                    $q->orWhereIn('group_id', $groupIds);
                }
            })
            ->orderBy('start')
            ->get();

        $map = [];
        foreach ($courses as $course) {
            $ownGroupIds = $groupsByCourse[$course->id] ?? [];

            $map[$course->id] = new self(
                $sessions->filter(fn (Schedule $s) => (int) $s->course_id === (int) $course->id
                    || ($s->group_id !== null && in_array($s->group_id, $ownGroupIds, false)))
                    ->values(),
                $now,
            );
        }

        return $map;
    }

    /** Курс вообще привязан к календарю? */
    public function hasCalendar(): bool
    {
        return $this->sessions->isNotEmpty();
    }

    /**
     * Потоки курса — занятия, сгруппированные по группе-потоку (H3115).
     *
     * У курса может быть НЕСКОЛЬКО потоков одной программы: «Бхагавадгита 2ч
     * 2026» идёт по вторникам 11:30 (группа 125) и по субботам 12:00
     * (группа 128). Складывать их календари нельзя: получалось «осталось 10
     * занятий из 24» и «24 часа» у курса из 16 занятий — числа, не описывающие
     * ни одного реального студента, потому что каждый ходит ровно в один поток.
     *
     * Занятия без группы (`group_id` NULL) — общекурсовые, свой единственный
     * «поток».
     *
     * @return Collection<array-key, Collection<int, Schedule>>
     */
    public function streams(): Collection
    {
        return $this->sessions->groupBy(fn (Schedule $s) => $s->group_id ?? 'course');
    }

    /** У курса больше одного потока на календаре. */
    public function hasMultipleStreams(): bool
    {
        return $this->streams()->count() > 1;
    }

    /**
     * Длина курса в занятиях. При нескольких потоках это ДЛИННЕЙШИЙ поток, а не
     * сумма: программа одна, потоки её повторяют.
     */
    public function total(): int
    {
        return (int) ($this->streams()->map->count()->max() ?? 0);
    }

    /** Занятий уже прошло в самом «продвинутом» потоке. */
    public function past(): int
    {
        return (int) ($this->streams()->map(fn (Collection $s) => $this->pastIn($s))->max() ?? 0);
    }

    /** Занятий осталось, считая идущее сейчас. */
    public function remaining(): int
    {
        return $this->total() - $this->past();
    }

    /** @param  Collection<int, Schedule>  $sessions */
    private function pastIn(Collection $sessions): int
    {
        return $sessions->filter(fn (Schedule $s) => $this->endOf($s)->lt($this->now))->count();
    }

    /** Все занятия по календарю уже прошли — во ВСЕХ потоках. */
    public function isFinished(): bool
    {
        if (! $this->hasCalendar()) {
            return false;
        }

        return $this->streams()->every(
            fn (Collection $s) => $this->pastIn($s) === $s->count()
        );
    }

    /** Хотя бы один поток начался и ещё не кончился. */
    public function isUnderway(): bool
    {
        if (! $this->hasCalendar()) {
            return false;
        }

        return $this->streams()->contains(
            fn (Collection $s) => $this->pastIn($s) > 0 && $this->pastIn($s) < $s->count()
        );
    }

    /** Ближайшее (или идущее сейчас) занятие. */
    public function next(): ?Schedule
    {
        return $this->sessions->first(fn (Schedule $s) => $this->endOf($s)->gte($this->now));
    }

    public function first(): ?Schedule
    {
        return $this->sessions->first();
    }

    public function last(): ?Schedule
    {
        return $this->sessions->last();
    }

    /**
     * Штатный слот: «по вторникам в 11:00». Несколько дней в неделю —
     * «вт 11:00 · чт 19:00». Пустое расписание — null.
     */
    public function slotLabel(): ?string
    {
        if (! $this->hasCalendar()) {
            return null;
        }

        if (! $this->hasMultipleStreams()) {
            return $this->slotOf($this->sessions, long: true);
        }

        // Несколько потоков — короткой формой каждый: «вт 11:30 · сб 12:00».
        $slots = $this->streams()
            ->map(fn (Collection $sessions) => $this->slotOf($sessions))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return $slots->isEmpty() ? null : $slots->implode(' · ');
    }

    /**
     * Слот одного набора занятий. `long` даёт «по вторникам в 11:30», иначе
     * «вт 11:30». Больше трёх разных дней — это уже не слот, честнее промолчать
     * и показать ближайшее занятие датой.
     *
     * @param  Collection<int, Schedule>  $sessions
     */
    private function slotOf(Collection $sessions, bool $long = false): ?string
    {
        if ($sessions->isEmpty()) {
            return null;
        }

        /** @var Collection<int, Collection<int, Schedule>> $byWeekday */
        $byWeekday = $sessions->groupBy(fn (Schedule $s) => $s->start->dayOfWeekIso);

        if ($byWeekday->count() === 1) {
            $isoDay = (int) $byWeekday->keys()->first();
            $time = $this->modalTime($byWeekday->first());

            return $long
                ? 'по '.self::WEEKDAYS_PLURAL[$isoDay].' в '.$time
                : self::WEEKDAYS_SHORT[$isoDay].' '.$time;
        }

        if ($byWeekday->count() > 3) {
            return null;
        }

        return $byWeekday
            ->sortKeys()
            ->map(fn (Collection $group, $isoDay) => self::WEEKDAYS_SHORT[(int) $isoDay].' '.$this->modalTime($group))
            ->implode(' · ');
    }

    /** «25 августа, вт, 11:00» для ближайшего занятия. */
    public function nextLabel(): ?string
    {
        $next = $this->next();
        if ($next === null) {
            return null;
        }

        return $next->start->translatedFormat('j F').', '
            .self::WEEKDAYS_SHORT[$next->start->dayOfWeekIso].', '
            .$next->start->format('H:i');
    }

    /**
     * «осталось 2 занятия из 16» — честный прогресс вместо тишины.
     *
     * При НЕСКОЛЬКИХ потоках молчит: один общий остаток описывал бы студента,
     * которого не существует (каждый ходит ровно в один поток). Поштучно потоки
     * называет {@see streamLines()}.
     */
    public function progressLabel(): ?string
    {
        if (! $this->hasCalendar() || $this->hasMultipleStreams()) {
            return null;
        }

        return $this->progressIn($this->sessions);
    }

    /**
     * Прогресс по каждому потоку отдельно: «вт 11:30 — осталось 2 из 16».
     * Один поток — пустой список (для него есть progressLabel()).
     *
     * @return array<int, string>
     */
    public function streamLines(): array
    {
        if (! $this->hasMultipleStreams()) {
            return [];
        }

        return $this->streams()
            ->map(function (Collection $sessions) {
                $slot = $this->slotOf($sessions);
                $progress = $this->progressIn($sessions);

                if ($slot === null) {
                    return $progress;
                }

                return $progress === null ? $slot : $slot.' — '.$progress;
            })
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    /** @param  Collection<int, Schedule>  $sessions */
    private function progressIn(Collection $sessions): ?string
    {
        if ($sessions->isEmpty()) {
            return null;
        }

        $total = $sessions->count();
        $past = $this->pastIn($sessions);

        if ($past === $total) {
            return 'все '.$total.' '
                .Plural::ru($total, 'занятие прошло', 'занятия прошли', 'занятий прошли');
        }

        if ($past === 0) {
            return null; // поток ещё не начался — «осталось 16 из 16» ничего не сообщает
        }

        $remaining = $total - $past;

        return 'осталось '.$remaining.' '
            .Plural::ru($remaining, 'занятие', 'занятия', 'занятий')
            .' из '.$total;
    }

    /**
     * Астрономические часы по календарю. Нужны как честная замена ручному
     * `courses.hours_count`, который у большинства живых потоков не заполнен.
     *
     * При нескольких потоках — часы ДЛИННЕЙШЕГО потока, а не сумма: студент
     * проходит программу один раз, в своём потоке. Сумма давала «24 часа» у
     * курса из 16 часовых занятий (H3115).
     */
    public function hours(): ?int
    {
        if (! $this->hasCalendar()) {
            return null;
        }

        $hours = (int) round(
            (float) $this->streams()->map(
                fn (Collection $sessions) => $sessions->sum(
                    fn (Schedule $s) => $s->start->diffInMinutes($this->endOf($s))
                )
            )->max() / 60
        );

        return $hours > 0 ? $hours : null;
    }

    private function endOf(Schedule $session): Carbon
    {
        return $session->end ?? $session->start->copy()->addHours(Schedule::DEFAULT_DURATION_HOURS);
    }

    /**
     * Самое частое время начала в группе. Ничьи разрешаются в пользу более
     * раннего времени — так подпись не «сдвигает» курс позже, чем он идёт.
     *
     * @param  Collection<int, Schedule>  $group
     */
    private function modalTime(Collection $group): string
    {
        $counts = $group
            ->groupBy(fn (Schedule $s) => $s->start->format('H:i'))
            ->map(fn (Collection $same) => $same->count())
            ->sortKeys();

        $top = $counts->max();
        $winner = $counts->search($top);

        // Явного большинства нет — берём самое раннее время, а не случайное.
        if ($top / max(1, $group->count()) < self::SLOT_MAJORITY) {
            $winner = $counts->keys()->first();
        }

        return (string) $winner;
    }
}
