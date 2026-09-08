<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * H4328: полный пост расписания курса — точный формат MG (07-09-2026):
 *
 *   Расписание курса «Введение в индийскую философию»
 *   Еженедельно по субботам в 11:00 (по МСК)
 *
 *   Обзорное занятие (не в счет 16):
 *   28 февраля 2026 (суббота), 11:00
 *
 *   1-е занятие: 7 марта 2026 (суббота), 11:00
 *   2-е занятие: 14 марта 2026 (суббота), 11:00
 *   3-е занятие: 21 марта 2026 (суббота), 11:00
 *   4-е занятие: 28 марта 2026 (суббота), 11:00
 *
 *   5-е занятие: 4 апреля 2026 (суббота), 11:00
 *   ...
 *
 * Решения MG: ритм-строка выводится автоматически из расписания; обзорное —
 * опционально (is_overview-строка), когда его нет — пост начинается с
 * «1-е занятие» без пометок (первое может быть пробным, а пробного может
 * не быть вовсе — текст поста от этого не меняется). Единица поста —
 * ГРУППА (поток): расписания и чаты обучения живут на group.
 *
 * Даты форматируются вручную (родительный падеж месяца + именительный
 * день недели): app locale = en, а Carbon locale('ru')->translatedFormat('F')
 * даёт именительный «март», не «марта» — хардкод надёжнее ICU.
 */
final class FullSchedulePost
{
    /**
     * @param  string|null  $cadence  «Еженедельно по субботам в 11:00 (по МСК)»
     * @param  array{label: string, date: string}|null  $overview
     * @param  list<array{label: string, date: string}>  $lessons  метки БЕЗ двоеточия
     */
    private function __construct(
        public readonly string $title,
        public readonly ?string $cadence,
        public readonly ?array $overview,
        public readonly array $lessons,
    ) {}

    /**
     * Пост для потока группы. Null — у группы нет занятий.
     */
    public static function forGroup(Group $group, bool $groupSuffix = false): ?self
    {
        $sessions = Schedule::query()
            ->where('group_id', $group->id)
            ->orderBy('start')
            ->get();

        return self::compose($group->name, $sessions, $groupSuffix);
    }

    /**
     * Посты для курса — по одному на группу с расписанием (поток), суффикс
     * группы в заголовке только когда групп больше одной. Строки, висящие
     * на course_id без группы потока, идут отдельным постом-фолбэком.
     *
     * @return list<self>
     */
    public static function forCourse(Course $course): array
    {
        $multi = $course->groups()->count() > 1;

        $posts = [];
        $covered = collect();

        foreach ($course->groups as $group) {
            $sessions = Schedule::query()->where('group_id', $group->id)->orderBy('start')->get();
            if ($sessions->isEmpty()) {
                continue;
            }

            $post = self::compose($course->title, $sessions, $multi);
            if ($post !== null) {
                $posts[] = $post;
                $covered = $covered->merge($sessions->pluck('id'));
            }
        }

        // Фолбэк: занятия привязаны к курсу напрямую, без группы (или группа
        // не в course.groups) — встречается на старых курсах.
        $orphans = Schedule::query()
            ->where('course_id', $course->id)
            ->whereNotIn('id', $covered->all())
            ->orderBy('start')
            ->get();
        if ($orphans->isNotEmpty()) {
            $post = self::compose($course->title, $orphans, false);
            if ($post !== null) {
                $posts[] = $post;
            }
        }

        return $posts;
    }

    /**
     * @param  Collection<int, Schedule>  $sessions
     */
    private static function compose(string $courseTitle, Collection $sessions, bool $groupSuffix): ?self
    {
        if ($sessions->isEmpty()) {
            return null;
        }

        $title = 'Расписание курса «'.$courseTitle.'»'.($groupSuffix ? ' — группа '.$sessions->first()->group?->name : '');

        $overview = $sessions->firstWhere('is_overview', true);
        $lessons = $sessions->reject(fn (Schedule $s): bool => (bool) $s->is_overview)
            ->filter(fn (Schedule $s): bool => $s->start !== null)
            ->values();

        $lessonLines = $lessons->map(fn (Schedule $s, int $i): array => [
            'label' => ($i + 1).'-е занятие',
            'date' => self::formatDate($s->start),
        ])->all();

        $overviewData = null;
        if ($overview !== null && $overview->start !== null) {
            $overviewData = [
                'label' => 'Обзорное занятие (не в счет '.$lessons->count().')',
                'date' => self::formatDate($overview->start),
            ];
        }

        return new self(
            $title,
            self::cadenceLine($lessons),
            $overviewData,
            $lessonLines,
        );
    }

    /** Текст поста без разметки (превью в админке, dry-run, тесты). */
    public function text(): string
    {
        $parts = [$this->title];

        if ($this->cadence !== null) {
            $parts[] = $this->cadence;
        }

        if ($this->overview !== null) {
            $parts[] = $this->overview['label'].":\n".$this->overview['date'];
        }

        $parts[] = $this->joinLessons("\n");

        return implode("\n\n", array_filter($parts, fn (string $p): bool => $p !== ''));
    }

    /**
     * Telegram HTML — правка MG (08-09-2026): жирным только МЕТКА («1-е
     * занятие:», «Обзорное занятие (не в счет N):»), дата в строке — обычным.
     * Заголовок и ритм без жирного. Пустая строка после каждого 4-го.
     */
    public function telegramHtml(): string
    {
        return implode("\n", $this->telegramLines());
    }

    /** HTML для сайта (страница курса, виджет): та же структура, <strong>/<br>. */
    public function html(): string
    {
        $esc = fn (string $line): string => htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
        $bold = fn (string $line): string => '<strong>'.$esc($line).'</strong>';

        $head = $esc($this->title);
        if ($this->cadence !== null) {
            $head .= '<br>'.$esc($this->cadence);
        }

        $lines = [];

        if ($this->overview !== null) {
            $lines[] = $bold($this->overview['label']).':';
            $lines[] = $esc($this->overview['date']);
        }

        foreach ($this->lessons as $i => $lesson) {
            $lines[] = $bold($lesson['label']).': '.$esc($lesson['date']);

            if (($i + 1) % 4 === 0 && isset($this->lessons[$i + 1])) {
                $lines[] = '';
            }
        }

        return '<p class="fs-head">'.$head.'</p>'."\n"
            .'<div class="fs-body">'.implode("<br>\n", $lines).'</div>';
    }

    /**
     * Строки поста: [заголовок, ритм?, '', обзорное x2?, занятия...] —
     * жирным помечается только метка занятия/обзорного, дата обычным;
     * '' = пустая строка-разделитель.
     *
     * @return list<string>
     */
    private function telegramLines(): array
    {
        $bold = fn (string $line): string => '<b>'.htmlspecialchars($line, ENT_QUOTES, 'UTF-8').'</b>';
        $plain = fn (string $line): string => htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

        $lines = [];
        $lines[] = $plain($this->title);

        if ($this->cadence !== null) {
            $lines[] = $plain($this->cadence);
        }

        $lines[] = '';

        if ($this->overview !== null) {
            $lines[] = $bold($this->overview['label']).':';
            $lines[] = $plain($this->overview['date']);
        }

        foreach ($this->lessons as $i => $lesson) {
            $lines[] = $bold($lesson['label']).': '.$plain($lesson['date']);

            if (($i + 1) % 4 === 0 && isset($this->lessons[$i + 1])) {
                $lines[] = '';
            }
        }

        return $lines;
    }

    /** Занятия одним блоком: «{label}: {date}» + пустая строка после каждого 4-го. */
    private function joinLessons(string $glue): string
    {
        $lines = [];

        foreach ($this->lessons as $i => $lesson) {
            $lines[] = $lesson['label'].': '.$lesson['date'];

            if (($i + 1) % 4 === 0 && isset($this->lessons[$i + 1])) {
                $lines[] = '';
            }
        }

        return implode($glue, $lines);
    }

    /**
     * Ритм-строка из расписания: «Еженедельно по субботам в 11:00 (по МСК)».
     * Два дня — «по вторникам и пятницам»; два времени — «в 11:00 и 19:00».
     * Нерегулярный ритм (3+ дня или 3+ времени) — строку не выдумываем.
     */
    private static function cadenceLine(Collection $lessons): ?string
    {
        if ($lessons->isEmpty()) {
            return null;
        }

        $days = $lessons->map(fn (Schedule $s): int => (int) $s->start->dayOfWeek)
            ->unique()
            // Сортировка Пн..Вс (Carbon: 0=Вс … 6=Сб).
            ->sortBy(fn (int $d): int => ($d + 6) % 7)
            ->values()
            ->all();

        $times = $lessons->map(fn (Schedule $s): string => $s->start->format('H:i'))
            ->unique()
            ->values()
            ->all();

        if (count($days) > 2 || count($times) > 2) {
            return null;
        }

        $dayNames = array_map(fn (int $d): string => self::WEEKDAY_PLURAL[$d], $days);
        // «по вторникам и четвергам» — у второго дня «по» уже не повторяем.
        $first = array_shift($dayNames);
        $rest = array_map(fn (string $d): string => preg_replace('/^по /u', '', $d) ?? $d, $dayNames);
        $dayPart = $first.($rest !== [] ? ' и '.implode(' и ', $rest) : '');

        $firstTime = array_shift($times);
        $timePart = 'в '.$firstTime.($times !== [] ? ' и '.implode(' и ', $times) : '');

        return 'Еженедельно '.$dayPart.' '.$timePart.' (по МСК)';
    }

    /** «7 марта 2026 (суббота), 11:00». */
    private static function formatDate(Carbon $start): string
    {
        $month = self::MONTHS_GENITIVE[(int) $start->format('n')];
        $weekday = self::WEEKDAY_NOMINATIVE[(int) $start->format('w')];

        return $start->format('j').' '.$month.' '.$start->format('Y').' ('.$weekday.'), '.$start->format('H:i');
    }

    private const MONTHS_GENITIVE = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    /** 0 = воскресенье … 6 = суббота (format('w')). */
    private const WEEKDAY_NOMINATIVE = [
        0 => 'воскресенье', 1 => 'понедельник', 2 => 'вторник', 3 => 'среда',
        4 => 'четверг', 5 => 'пятница', 6 => 'суббота',
    ];

    /** Пн..Вс + Вс(0) — для «Еженедельно по …». */
    private const WEEKDAY_PLURAL = [
        1 => 'по понедельникам', 2 => 'по вторникам', 3 => 'по средам', 4 => 'по четвергам',
        5 => 'по пятницам', 6 => 'по субботам', 0 => 'по воскресеньям',
    ];
}
