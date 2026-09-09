<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\ScheduleJoinClick;
use App\Models\User;
use App\Models\WebinarAttendance;
use App\Services\ClassRoster;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * H4392 (MG 08-09-2026): еженедельный пост «Кто на чём закончил» в чат
 * «Институт». По каждой идущей группе — весь ростер с последним РЕАЛЬНО
 * посещённым занятием каждого студента и пропуски 2+ подряд.
 *
 * Решения MG 08-09-2026 (чат) + поправка по живым данным (dry-run на проде):
 *  - «на чём закончил» = последний факт студента на занятии. Иерархия фактов
 *    перевернута ПРОБОЙ ДАННЫХ: из 1924 строк WebinarAttendance только 74
 *    привязаны к user_id (гости пишутся по имени), а ScheduleJoinClick —
 *    «надёжная привязка студентов к занятию» (кодбейс, H-серия) — 80/80 с
 *    user_id. Основной сигнал = КЛИК; attendance-with-user_id — усиление
 *    (строка без пометки), клик без attendance помечается «(по клику)»;
 *  - «пропустил» = занятие без факта (ни attendance, ни клика); серия от
 *    новейшего прошедшего назад; 2+ после хотя бы одного факта → «⚠️ пропустил
 *    N подряд»; ноль фактов → «не был ни разу (за N занятий)»;
 *  - группы = идущие: ≥1 прошедшего И ≥1 будущего занятия;
 *  - ростер = канонический ClassRoster (activeGroups — вышедшие/выпускники
 *    исключены, «единый источник правды» о том, кто должен быть на занятии).
 *
 * Нумерация занятий зеркалит FullSchedulePost::compose (обзорное не в счёт),
 * даты — тот же формат «1 сентября 2026 (вторник), 19:30».
 */
final class WeeklyFinishReport
{
    /**
     * Отчёт по всем идущим группам активных видимых курсов.
     *
     * @return list<array{course: Course, group: Group, pastCount: int, futureCount: int, students: list<array{user: User, last: ?array{label: string, date: string}, clicked: ?array{label: string, date: string}, missedStreak: int}>}
     */
    public static function build(): array
    {
        $courses = Course::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->whereHas('groups')
            ->with('groups:id,name')
            ->orderBy('title')
            ->get();

        $report = [];
        $familyCursors = []; // H4443: курсоры по семействам для лага

        foreach ($courses as $course) {
            foreach ($course->groups as $group) {
                $sessions = self::groupSessions($group);
                $past = $sessions->filter(fn (Schedule $s): bool => self::isPast($s))->values();
                $futureCount = $sessions->filter(
                    fn (Schedule $s): bool => $s->start !== null && $s->start->isFuture(),
                )->count();

                if ($past->isEmpty() || $futureCount === 0) {
                    continue; // не идущая группа: ещё не стартовала или закончилась
                }

                $report[] = [
                    'course' => $course,
                    'group' => $group,
                    // «Прошло N» — кураторская нумерация записей уроков (факт:
                    // Бюллер-27 провёл 35, а календарь БД помнит только 10 —
                    // MG 09-09 «прошли больше 20 занятий, проверь сам»).
                    'pastCount' => self::heldCount($course, $group, $past),
                    'futureCount' => $futureCount,
                    'students' => self::studentRows($group, $past),
                    'pastSchedules' => $past,
                ] + self::canvasData($course, $group, $past);
            }
        }

        // Детерминированный порядок: курс по названию, группа по названию.
        usort($report, fn (array $a, array $b): int => [$a['course']->title, $a['group']->name] <=> [$b['course']->title, $b['group']->name]);

        return $report;
    }

    /**
     * H4457-правда (MG 09-09): проведённых занятий считаем по ЗАПИСЯМ УРОКОВ —
     * кураторская нумерация в заголовках («2-е занятие», «#35, 16.06.26») —
     * факт ведения; календарь расписаний может помнить лишь недавний хвост
     * (Бюллер-27: записей 35, прошлых сессий в расписании 10 — ранняя история
     * в календарь не заведена). Обзорное не в счёт. Фолбэки: число записей →
     * прошлые сессии расписания.
     */
    private static function heldCount(Course $course, Group $group, $pastSchedules): int
    {
        $lessons = Lesson::where('course_id', $course->id)
            ->whereNotNull('lesson_date')->where('lesson_date', '<=', now())
            ->orderBy('lesson_date')
            ->get();

        $maxOrdinal = 0;
        $counted = 0;
        foreach ($lessons as $lesson) {
            $counted++;
            foreach ([
                '/\((\d+),\s*\d{2}\.\d{2}\.\d{2}\)/u',   // «(#35, 16.06.26)»
                '/(\d+)-е занятие/u',                     // «2-е занятие»
                '/#\s*(\d+)/u',                            // «#35»
            ] as $pattern) {
                if (preg_match($pattern, (string) $lesson->title, $m)) {
                    $maxOrdinal = max($maxOrdinal, (int) $m[1]);
                    break;
                }
            }
        }

        if ($maxOrdinal > 0) {
            return $maxOrdinal;
        }
        if ($counted > 0) {
            return $counted;
        }

        // Фолбэк: прошлые сессии расписания (обзорное не в счёт).
        return (int) $pastSchedules->reject(fn (Schedule $s): bool => (bool) $s->is_overview)->count();
    }

    /**
     * H4435: данные канвы группы — курсор по учебнику (макс. читка), итого
     * учебника, канва-lag против медианы семейства, проекция «до конца».
     * Наши занятия и уроки учебника — разные шкалы (модель v2, MG 09-09).
     *
     * @return array{canvasCursor: int, canvasTotal: int, canvasFamily: string, canvasLag: int, canvasProjection: int|null}
     */
    private static function canvasData(Course $course, Group $group, $past): array
    {
        $family = TextbookScale::courseFamilyPublic((string) $course->title);
        if ($family === null) {
            return ['canvasCursor' => 0, 'canvasTotal' => 0, 'canvasFamily' => '', 'canvasLag' => 0, 'canvasProjection' => null];
        }

        $total = TextbookScale::families()[$family]['total'];
        $lessons = Lesson::where('course_id', $course->id)
            ->whereNotNull('lesson_date')
            ->orderBy('lesson_date')
            ->get();

        $cursor = TextbookScale::cursor($lessons, $family);

        // Медиана курсоров идущих групп семейства — база канва-lag.
        $needle = $family === 'kochergina' ? '%Кочерг%' : '%Бю%';
        $cursors = [];
        $peers = Course::query()
            ->where('is_active', true)->where('is_visible', true)
            ->where('title', 'like', $needle)
            ->pluck('id');
        foreach ($peers as $pid) {
            $peerLessons = Lesson::where('course_id', $pid)
                ->whereNotNull('lesson_date')->orderBy('lesson_date')->get();
            $cursors[$pid] = TextbookScale::cursor($peerLessons, $family);
        }

        $projection = TextbookScale::projection($lessons, $cursor, $total, $family);
        $remainingSessions = $projection['projection_sessions'] ?? null;

        // H4443: блоки — маркер *X.Y* записи-курсора, итого из тарифов курса.
        $cursorBlock = 0;
        foreach ($lessons as $l) {
            $chitki = array_filter(
                TextbookScale::parseTitle((string) $l->title),
                fn (array $i): bool => $i['family'] === $family && $i['kind'] === 'chitka',
            );
            if ($chitki !== [] && max(array_column($chitki, 'lesson')) === $cursor) {
                $cursorBlock = TextbookScale::parseBlockMarker((string) $l->title)
                    ?? (int) ceil($cursor / TextbookScale::lessonsPerBlock());
                break; // первая запись с курсором — ближайшая по времени
            }
        }
        $blocksTotal = TextbookScale::blocksTotal($course->id, $total);
        $remainingBlocks = $remainingSessions !== null ? (int) ceil($remainingSessions / TextbookScale::lessonsPerBlock()) : null;

        // H4443: прогноз финала — свой темп группы, календарь с каникулами.
        $cadence = TextbookScale::weeklyCadence($past);
        $forecast = $remainingSessions !== null
            ? TextbookScale::finishForecast($remainingSessions, null, $cadence)
            : null;

        return [
            'canvasCursor' => $cursor,
            'canvasTotal' => $total,
            'canvasFamily' => $family,
            'canvasLag' => TextbookScale::lag($cursor, $cursors),
            'canvasProjection' => $remainingSessions,
            'canvasBlock' => $cursorBlock,
            'canvasBlocksTotal' => $blocksTotal,
            'canvasRemainingBlocks' => $remainingBlocks,
            'canvasForecast' => $forecast,
        ];
    }

    /**
     * Telegram HTML поста (может быть несколько сообщений — лимит 4096).
     *
     * @param  list<array{course: Course, group: Group, pastCount: int, futureCount: int, students: list<array{user: User, last: ?array{label: string, date: string}, clicked: ?array{label: string, date: string}, missedStreak: int}>}  $report
     * @return list<string> — чанки, каждый <= self::MAX_TELEGRAM_LENGTH
     */
    public static function telegramChunks(array $report, ?Carbon $weekStart = null): array
    {
        if ($report === []) {
            return [];
        }

        $week = ($weekStart ?? now())->format('d.m.Y');

        // Плоский список сегментов: шапка, на группу — заголовок + строки
        // студентов. Упаковка по сегментам: даже гигантская группа не ломает
        // лимит (одиночный сегмент-студент лимита не достигает физически).
        $segments = ['<b>Кто на чём закончил — неделя '.$week.'</b>'];

        foreach ($report as $row) {
            // «Курс — группа» дублируется, когда имя группы равно названию курса.
            $head = $row['course']->title === $row['group']->name
                ? self::esc($row['course']->title)
                : self::esc($row['course']->title).' — '.self::esc($row['group']->name);

            // H4435/H4443: канва-строка — курсор и блоки по учебнику ОТДЕЛЬНО от
            // наших занятий (две шкалы не смешиваются). Деньги в пост НЕЛЬЗЯ.
            if (($row['canvasTotal'] ?? 0) > 0 && ($row['canvasCursor'] ?? 0) > 0) {
                $head .= "\nКанва: урок ".$row['canvasCursor'].'/'.$row['canvasTotal'];
                if (($row['canvasBlock'] ?? 0) > 0) {
                    $head .= ' · блок '.$row['canvasBlock'].'/'.$row['canvasBlocksTotal'];
                    if (($row['canvasRemainingBlocks'] ?? null) !== null) {
                        $head .= ' (осталось '.$row['canvasRemainingBlocks'].')';
                    }
                }
                if (($row['canvasLag'] ?? 0) !== 0) {
                    $head .= ' ('.($row['canvasLag'] > 0 ? '+' : '').$row['canvasLag'].' к медиане)';
                }
                if (($row['canvasProjection'] ?? null) !== null) {
                    $head .= ' · до конца ≈ '.$row['canvasProjection'].' наших занятий';
                    if (($row['canvasForecast'] ?? null) !== null) {
                        $head .= ' · финал: '.$row['canvasForecast'];
                    }
                }
            }

            $segments[] = '<b>'.$head.'</b>'
                .' (прошло '.$row['pastCount'].' · впереди '.$row['futureCount'].')';
            foreach ($row['students'] as $s) {
                // H4435: имя студента — ссылка на его страницу в админке.
                $segments[] = '<a href="'.self::esc(self::userUrl($s['user'])).'">'.self::esc(self::studentLine($s)).'</a>';
            }
        }

        // Пакуем сегменты в сообщения с запасом под лимит 4096.
        $chunks = [];
        $current = '';
        foreach ($segments as $segment) {
            $candidate = $current === '' ? $segment : $current."\n".$segment;
            if (mb_strlen($candidate) > self::MAX_TELEGRAM_LENGTH && $current !== '') {
                $chunks[] = $current;
                $current = $segment;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private const MAX_TELEGRAM_LENGTH = 3500;

    /**
     * Строка студента:
     *  - с фактом: «Иванова — 9-е занятие, 1 сентября 2026 (вторник), 19:30»,
     *    клик-only факт помечается «(по клику)»;
     *  - серия неявок 2+ после факта: «⚠️ пропустил N подряд»;
     *  - без фактов: «не был ни разу (за N занятий)» + «(кликал: …)» если клики были.
     */
    private static function studentLine(array $s): string
    {
        $name = trim((string) $s['user']->name);
        if ($name === '') {
            $name = (string) $s['user']->email;
        }

        $line = $name;

        $last = $s['last'];
        if ($last !== null) {
            $line .= ' — '.$last['label'].', '.$last['date'];
            if ($last['kind'] === 'clicked') {
                $line .= ' (по клику)';
            }
            // H4435: предмет канвы отдельной шкалой («Кочергина 4 (читка)»),
            // никогда не смешивается с нумерацией наших занятий.
            if (! empty($s['canvas'])) {
                $line .= ' · '.$s['canvas'];
            }
        } else {
            $line .= ' — не был ни разу (за '.$s['pastSessions'].' '.self::pluralLessons($s['pastSessions']).')';
            if (isset($s['clicked'])) {
                $line .= ' (кликал: '.$s['clicked']['label'].', '.$s['clicked']['date'].')';
            }
        }

        if ($last !== null && $s['missedStreak'] >= 2) {
            $line .= ' ⚠️ пропустил '.$s['missedStreak'].' подряд';
        }

        return $line;
    }

    /** Русское множественное: занятие/занятия/занятий. */
    private static function pluralLessons(int $n): string
    {
        if ($n % 10 === 1 && $n % 100 !== 11) {
            return 'занятие';
        }
        if (in_array($n % 10, [2, 3, 4], true) && ! in_array($n % 100, [12, 13, 14], true)) {
            return 'занятия';
        }

        return 'занятий';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** H4435: ссылка на студента в админке (Filament ViewUser, панель /admin). */
    private static function userUrl(User $user): string
    {
        return rtrim(url('/admin'), '/').'/users/'.$user->id;
    }

    /**
     * Занятия группы: привязанные к ней напрямую либо к её курсам (group_id
     * пуст) — зеркально ClassAttendanceService::forGroup.
     *
     * @return Collection<int, Schedule>
     */
    private static function groupSessions(Group $group)
    {
        $courseIds = $group->courses()->pluck('courses.id');

        return Schedule::query()
            ->whereNotNull('start')
            ->where(function ($q) use ($group, $courseIds): void {
                $q->where('group_id', $group->id)
                    ->orWhere(fn ($q2) => $q2->whereNull('group_id')->whereIn('course_id', $courseIds));
            })
            ->orderBy('start')
            ->get();
    }

    /**
     * Строки студентов группы (канонический ростер ClassRoster — activeGroups,
     * вышедшие/выпускники исключены): последний ФАКТ на занятии (attendance
     * с user_id или клик по ссылке) + серия полных неявок от новейшего
     * прошедшего занятия назад.
     *
     * @param  Collection<int, Schedule>  $past  прошедшие, по возрастанию start
     * @return list<array{user: User, last: ?array{label: string, date: string, kind: string}, clicked: ?array{label: string, date: string}, missedStreak: int, pastSessions: int}>
     */
    private static function studentRows(Group $group, $past): array
    {
        $scheduleIds = $past->pluck('id');

        $attended = WebinarAttendance::query()
            ->whereIn('schedule_id', $scheduleIds)
            ->whereNotNull('user_id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('schedule_id')->unique()->values());

        $clicked = ScheduleJoinClick::query()
            ->whereIn('schedule_id', $scheduleIds)
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('schedule_id')->unique()->values());

        $labels = self::scheduleLabels($past);

        // Канонический ростер группы: активные участники (group_user.left_at IS NULL).
        $students = User::query()
            ->whereHas('activeGroups', fn ($q) => $q->where('groups.id', $group->id))
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($students as $user) {
            $userAttended = $attended->get($user->id, collect());
            $userClicked = $clicked->get($user->id, collect());

            // Последний факт: новейшее прошедшее занятие с attendance ИЛИ кликом;
            // attendance сильнее — если на одном занятии есть и то, и другое,
            // факт «attended» (без пометки «по клику»).
            $lastSchedule = $past->reverse()->first(
                fn (Schedule $s): bool => $userAttended->contains($s->id) || $userClicked->contains($s->id),
            );
            $last = null;
            if ($lastSchedule !== null) {
                $kind = $userAttended->contains($lastSchedule->id) ? 'attended' : 'clicked';
                $last = $labels[$lastSchedule->id] + ['kind' => $kind];
            }

            // Weaker-сигнал для «не был ни разу»: были клики, но ни одного посещения.
            $clickedOnlySchedule = $lastSchedule === null
                ? $past->reverse()->first(fn (Schedule $s): bool => $userClicked->contains($s->id))
                : null;
            $clickedLast = $clickedOnlySchedule !== null ? $labels[$clickedOnlySchedule->id] : null;

            // Серия полных неявок (ни факта, ни клика) от новейшего назад.
            $missedStreak = 0;
            foreach ($past->reverse() as $schedule) {
                $hadFact = $userAttended->contains($schedule->id) || $userClicked->contains($schedule->id);
                if ($hadFact) {
                    break;
                }
                $missedStreak++;
            }

            $rows[] = [
                'user' => $user,
                'last' => $last,
                'canvas' => $lastSchedule !== null && $lastSchedule->start !== null
                    ? self::canvasLabelForDate($group, $lastSchedule->start->copy()->startOfDay())
                    : null,
                'clicked' => $lastSchedule === null && $clickedLast !== null ? $clickedLast : null,
                'missedStreak' => $missedStreak,
                'pastSessions' => $past->count(),
            ];
        }

        return $rows;
    }

    /**
     * H4435: подпись канвы для даты нашего занятия — запись урока с той же
     * lesson_date → предмет канвы из заголовка («Кочергина 4 (читка)»).
     * Две шкалы раздельны (MG 09-09).
     */
    private static function canvasLabelForDate(Group $group, Carbon $date): ?string
    {
        static $cache = [];
        $key = $group->id.'|'.$date->format('Y-m-d');
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $course = $group->courses()->first();
        $family = $course !== null ? TextbookScale::courseFamilyPublic((string) $course->title) : null;
        if ($course === null || $family === null) {
            return $cache[$key] = null;
        }

        $lesson = Lesson::where('course_id', $course->id)
            ->whereDate('lesson_date', $date->format('Y-m-d'))
            ->orderBy('id')
            ->first();

        if ($lesson === null) {
            return $cache[$key] = null;
        }

        // Прямая колонка приоритетна, иначе парсер заголовка.
        if (($lesson->textbook_lesson ?? null) !== null) {
            return $cache[$key] = TextbookScale::label($family, (int) $lesson->textbook_lesson, 'chitka');
        }

        $items = TextbookScale::parseTitle((string) $lesson->title);
        $chitki = array_values(array_filter($items, fn (array $i): bool => $i['family'] === $family && $i['kind'] === 'chitka'));
        if ($chitki === []) {
            return $cache[$key] = null;
        }

        $max = max(array_map(fn (array $i): int => $i['lesson'], $chitki));

        return $cache[$key] = TextbookScale::label($family, $max, 'chitka');
    }

    /**
     * Метки занятий: «N-е занятие» (обзорное не в счёт — зеркально
     * FullSchedulePost) + формат даты тот же.
     *
     * @param  Collection<int, Schedule>  $past
     * @return array<int, array{label: string, date: string}> по schedule_id
     */
    private static function scheduleLabels($past): array
    {
        $lessonNumber = 0;
        $labels = [];

        foreach ($past as $schedule) {
            if ((bool) $schedule->is_overview) {
                $labels[$schedule->id] = ['label' => 'Обзорное занятие', 'date' => self::formatDate($schedule->start)];
            } else {
                $lessonNumber++;
                $labels[$schedule->id] = ['label' => $lessonNumber.'-е занятие', 'date' => self::formatDate($schedule->start)];
            }
        }

        return $labels;
    }

    private static function formatDate(Carbon $start): string
    {
        return FullSchedulePost::formatDate($start);
    }

    /** Эффективный конец занятия (end ?? start + 2ч) уже позади — H4387. */
    private static function isPast(Schedule $s): bool
    {
        return $s->start !== null
            && ($s->end ?? $s->start->copy()->addHours(Schedule::DEFAULT_DURATION_HOURS))->isPast();
    }
}
