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
        /** Сколько нумерованных занятий уже прошло (обзорное «не в счет»). */
        public readonly int $pastCount = 0,
        /** ['date' => string, 'key' => int|'overview'] последнего прошедшего, null — ничего не прошло. */
        public readonly ?array $lastPast = null,
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

        // H4387: занятие «прошло», когда его эффективный конец уже позади
        // (end ?? start + DEFAULT_DURATION_HOURS) — идущее сейчас занятие ещё
        // не «последнее прошедшее», это та же семантика, что у upcomingSchedules.
        $pastCount = 0;
        $lastPast = null;

        $lessonLines = $lessons->map(function (Schedule $s, int $i) use (&$pastCount, &$lastPast): array {
            $isPast = self::isPast($s);
            if ($isPast) {
                $pastCount++;
                $lastPast = ['date' => self::formatDate($s->start), 'key' => $i];
            }

            return [
                'label' => ($i + 1).'-е занятие',
                'date' => self::formatDate($s->start),
                'start' => $s->start->toIso8601String(),
                'is_past' => $isPast,
            ];
        })->all();

        $overviewData = null;
        if ($overview !== null && $overview->start !== null) {
            $isPast = self::isPast($overview);
            if ($isPast) {
                $lastPast = ['date' => self::formatDate($overview->start), 'key' => 'overview'];
            }

            $overviewData = [
                'label' => 'Обзорное занятие (не в счет '.$lessons->count().')',
                'date' => self::formatDate($overview->start),
                'start' => $overview->start->toIso8601String(),
                'is_past' => $isPast,
            ];
        }

        return new self(
            $title,
            self::cadenceLine($lessons),
            $overviewData,
            $lessonLines,
            $pastCount,
            $lastPast,
        );
    }

    /** Эффективный конец занятия (end ?? start + 2ч) уже в прошлом. */
    private static function isPast(Schedule $s): bool
    {
        return ($s->end ?? $s->start->copy()->addHours(Schedule::DEFAULT_DURATION_HOURS))->isPast();
    }

    /** Строка-статус над списком: сколько прошло и каким было последнее. */
    private function statusLine(): ?string
    {
        if ($this->lastPast === null) {
            return null;
        }

        if ($this->pastCount > 0) {
            return 'Прошло занятий: '.$this->pastCount.' · последнее: '.$this->lastPast['date'];
        }

        return 'Последнее прошедшее: '.$this->lastPast['date'];
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

    /**
     * HTML для сайта (страница курса, виджет, /raspisanie). Жирным — только
     * заголовок курса (fs-head) и метки занятий; ритм-строка — обычным (правка
     * MG 08-09-2026), идёт первой строкой блока расписания.
     *
     * H4387 (MG 08-09-2026): по умолчанию прошедшие занятия скрыты — над
     * списком строка «Прошло занятий: N · последнее: …» и кнопка раскрытия;
     * последнее прошедшее занятие подсвечено жёлтым (fs-last, инлайн-стиль —
     * читается и на тёмной, и на светлой поверхности). Нумерация занятий
     * абсолютная. $options['past'] = 'visible' — прежняя разметка без скрытия.
     *
     * Каждая строка обёрнута в span и несёт СВОЙ <br>: скрытая строка уносит
     * разрыв с собой, после раскрытия разметка совпадает с классической.
     * Пустые строки-разделители приклеиваются к СЛЕДУЮЩЕЙ строке.
     */
    public function html(array $options = []): string
    {
        $esc = fn (string $line): string => htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
        $bold = fn (string $line): string => '<strong>'.$esc($line).'</strong>';

        // H4434: на публичных поверхностях (страница /raspisanie, страница курса,
        // виджет) даты обёрнуты в <time data-msk-timestamp> — клиентский JS
        // конвертирует их в зону устройства гостя. TG и админка остаются чистым текстом.
        $dateFn = ($options['client_tz'] ?? false)
            ? fn (Carbon $s): string => self::formatDateWithTimestamp($s)
            : fn (Carbon $s): string => self::formatDate($s);

        $head = '<p class="fs-head"><strong>'.$esc($this->title).'</strong></p>';

        $lines = [];

        if ($this->cadence !== null) {
            $lines[] = ['h' => $esc($this->cadence), 'past' => false, 'last' => false];
            $lines[] = ['h' => '', 'past' => false, 'last' => false];
        }

        if ($this->overview !== null) {
            $overviewPast = (bool) $this->overview['is_past'];
            $lines[] = ['h' => $bold($this->overview['label']).':', 'past' => $overviewPast, 'last' => false];
            $lines[] = ['h' => $dateFn(Carbon::parse($this->overview['start'])), 'past' => $overviewPast, 'last' => $overviewPast && $this->lastPast !== null && $this->lastPast['key'] === 'overview'];
        }

        foreach ($this->lessons as $i => $lesson) {
            $lines[] = [
                'h' => $bold($lesson['label']).': '.$dateFn(Carbon::parse($lesson['start'])),
                'past' => (bool) $lesson['is_past'],
                'last' => (bool) $lesson['is_past'] && $this->lastPast !== null && $this->lastPast['key'] === $i,
            ];

            if (($i + 1) % 4 === 0 && isset($this->lessons[$i + 1])) {
                $lines[] = ['h' => '', 'past' => false, 'last' => false];
            }
        }

        $hidePast = ($options['past'] ?? 'hidden') !== 'visible' && $this->lastPast !== null;

        if (! $hidePast) {
            return $head."\n"
                .'<div class="fs-body">'.implode("<br>\n", array_map(fn (array $l): string => self::wrapLine($l['h'], $l['last']), $lines)).'</div>';
        }

        // Режим скрытия: прошедшие строки — span.fs-past[hidden], статус и
        // кнопка живут внутри .fs-body (delegated JS тогглит по нему).
        $out = [];
        $gap = '';
        $total = count($lines);
        foreach ($lines as $idx => $line) {
            $br = $idx === $total - 1 ? '' : "<br>\n";
            if ($line['h'] === '') {
                $gap .= $br;

                continue;
            }
            $attrs = $line['past'] ? ' hidden' : '';
            $classes = 'fs-line'.($line['past'] ? ' fs-past' : '').($line['last'] ? ' fs-last' : '');
            $style = $line['last'] ? self::LAST_STYLE : '';
            $out[] = '<span class="'.$classes.'"'.$style.$attrs.'>'.$gap.$line['h'].$br.'</span>';
            $gap = '';
        }

        $inner = '<p class="fs-status">'.$esc((string) $this->statusLine()).'</p>'
            .'<button type="button" class="fs-toggle" aria-expanded="false">Показать прошедшие занятия</button>'
            .implode('', $out);

        return $head."\n"
            .'<div class="fs-body" data-fs-past="hidden">'.$inner.'</div>';
    }

    private const LAST_STYLE = ' style="background:#FDE047;color:#1F2430;padding:0 6px;border-radius:6px;"';

    /** Жёлтая подсветка последнего прошедшего; строка без подсветки — как есть. */
    private static function wrapLine(string $h, bool $last): string
    {
        if ($h === '') {
            return '';
        }

        return $last
            ? '<span class="fs-last"'.self::LAST_STYLE.'>'.$h.'</span>'
            : $h;
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

    /** «7 марта 2026 (суббота), 11:00». Публично с H4392 — переиспользуется отчётом о посещениях. */
    public static function formatDate(Carbon $start): string
    {
        $month = self::MONTHS_GENITIVE[(int) $start->format('n')];
        $weekday = self::WEEKDAY_NOMINATIVE[(int) $start->format('w')];

        return $start->format('j').' '.$month.' '.$start->format('Y').' ('.$weekday.'), '.$start->format('H:i');
    }

    /**
     * H4434 — клиентская конверсия для гостей и embed-виджета (MG 09-09-2026):
     * дата остаётся московской строкой, но несёт data-msk-timestamp (unix) —
     * vanilla-JS на публичных поверхностях перезаписывает время на зону
     * устройства без cookie и без записи в БД (работает в iframe).
     */
    public static function formatDateWithTimestamp(Carbon $start): string
    {
        return '<time data-msk-timestamp="'.$start->timestamp.'" datetime="'
            .$start->timezone('UTC')->toIso8601String().'">'
            .htmlspecialchars(self::formatDate($start), ENT_QUOTES, 'UTF-8').'</time>';
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
