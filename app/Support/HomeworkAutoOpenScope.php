<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\Lesson;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Кто в охвате автооткрытия приёма ДЗ (H3078, рулинг MG 18-08-2026).
 *
 * До 18-08-2026 охват был allowlist'ом слагов в `.env`, и он дважды подвёл
 * молча: гр.62 забыли внести (H3068), а каждый следующий курс молчал бы так же.
 * Теперь охват — правило, а не список:
 *
 *   курс в охвате ⇔ его ПЕРВЫЙ урок не раньше `min_course_start`
 *                   И слаг не в `exclude_course_slugs`
 *
 * Отдельно от охвата стоит `policy_epoch` — она режет не курсы, а УРОКИ, чей
 * момент открытия уже в прошлом. Две защёлки решают разные задачи и обе нужны:
 * без первой открылись бы архивы гр.51/53/55/57/58, без второй — 41 урок
 * задним числом в живых курсах, начавшихся летом.
 */
class HomeworkAutoOpenScope
{
    /**
     * Id курсов в охвате. Два запроса (курсы + первый урок каждого), без кэша:
     * зовётся один раз за проход `homework:auto-open`, на ~150 курсах это
     * дешевле, чем риск отдать протухший список после правки данных.
     *
     * @return list<int>
     */
    public static function courseIdsInScope(): array
    {
        $scope = (string) config('homework.auto_open.scope', 'all');
        $exclude = (array) config('homework.auto_open.exclude_course_slugs', []);

        $query = Course::query()->select('id');

        if ($scope === 'listed') {
            // Путь отката к прежнему allowlist-поведению.
            $slugs = (array) config('homework.auto_open.course_slugs', []);

            if ($slugs === []) {
                return [];
            }

            $query->whereIn('slug', $slugs);
        }

        if ($exclude !== []) {
            $query->whereNotIn('slug', $exclude);
        }

        $ids = $query->pluck('id')->map(fn ($id) => (int) $id)->all();

        $minStart = self::minCourseStart();

        if ($minStart === null || $ids === []) {
            return $ids;
        }

        // `lessons.course_id` — varchar, поэтому сравниваем строками на стороне
        // PHP, а не полагаемся на приведение типов в SQL.
        $firstLesson = Lesson::query()
            ->whereIn('course_id', $ids)
            ->groupBy('course_id')
            ->select('course_id', DB::raw('MIN(lesson_date) as first_lesson'))
            ->pluck('first_lesson', 'course_id');

        return array_values(array_filter($ids, function (int $id) use ($firstLesson, $minStart): bool {
            $first = $firstLesson[(string) $id] ?? $firstLesson[$id] ?? null;

            // Курс без уроков ещё не начался — он в охвате, отсекать нечего.
            if ($first === null) {
                return true;
            }

            return Carbon::parse((string) $first)->startOfDay()->greaterThanOrEqualTo($minStart);
        }));
    }

    /** Нижняя граница даты первого урока курса; null — границы нет. */
    public static function minCourseStart(): ?CarbonInterface
    {
        $raw = trim((string) config('homework.auto_open.min_course_start', ''));

        return $raw === '' ? null : Carbon::parse($raw, 'Europe/Moscow')->startOfDay();
    }

    /** Урок с моментом открытия раньше эпохи не открывается никогда. */
    public static function policyEpoch(): ?CarbonInterface
    {
        $raw = trim((string) config('homework.auto_open.policy_epoch', ''));

        return $raw === '' ? null : Carbon::parse($raw, 'Europe/Moscow')->startOfDay();
    }

    /**
     * Курс Кочергиной — опознаётся по слагу, тем же соглашением, по которому
     * их заводят. Значит две вещи: текст задания берётся из учебника, и
     * действует потолок обязательных сдач.
     */
    public static function isKochergina(?string $courseSlug): bool
    {
        $prefix = (string) config('homework.auto_open.kochergina_slug_prefix', '');

        if ($prefix === '' || $courseSlug === null || $courseSlug === '') {
            return false;
        }

        return str_starts_with($courseSlug, $prefix);
    }

    /**
     * «Обязательная сдача в группах санскрита заканчивается до 6 урока
     * Кочергиной, 6 уже не сдают» (MG 18-08-2026).
     *
     * Считаются уроки курса с ВКЛЮЧЁННЫМ приёмом, а не `textbook_lesson`:
     * ту колонку заполняет человек, и весь смысл H3068/H3078 в том, чтобы
     * от неё не зависеть. Плата за это названа честно: «читка» внутри курса
     * съедает слот наравне с обычным занятием, так что потолок считает
     * выданные ДЗ, а не номера занятий учебника.
     */
    public static function kocherginaCapReached(Lesson $lesson): bool
    {
        $max = (int) config('homework.auto_open.kochergina_max_homeworks', 0);

        if ($max <= 0) {
            return false;
        }

        $lesson->loadMissing('course');

        if (! self::isKochergina($lesson->course?->slug)) {
            return false;
        }

        $opened = Lesson::query()
            ->where('course_id', $lesson->course_id)
            ->where('homework_enabled', true)
            ->whereKeyNot($lesson->getKey())
            ->count();

        return $opened >= $max;
    }
}
