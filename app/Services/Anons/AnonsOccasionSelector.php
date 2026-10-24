<?php

declare(strict_types=1);

namespace App\Services\Anons;

use App\Models\Course;
use App\Services\Schedule\FullSchedulePost;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * H6329: отбор поводов анонс-кампании по виду занятия (канон
 * docs/SCHEDULE_KINDS_CANON_ANONS_SITE_09-10-2026, рулинг MG 09-10-2026:
 * в анонс-план входят все 4 вида занятий расписания).
 *
 * Семантика kind — та же, что в публичном фиде /api/public/schedule
 * (H6313, PublicScheduleController::buildFeed): «обзорное» = is_overview
 * строки; «разовое» = курс-уровневый признак — у ВСЕХ постов курса
 * FullSchedulePost::forCourse cadence=null (нет недельного ритма);
 * «обычное» = остальное. Пробные в отбор отдельным видом не попадают —
 * у пробного kind нет по канону, в фиде они видны bookable/book_token.
 *
 * Правило продублировано, а не импортировано из контроллера, сознательно:
 * контроллер фида — контракт H6313, его не трогаем; согласованность фида
 * и отбора закреплена тестом AnonsOccasionSelectorTest::test_kind_matches_public_feed.
 *
 * Отбор воспроизводим: чистый запрос без side-эффектов, порядок
 * детерминирован (start, slug курса, id строки расписания).
 */
final class AnonsOccasionSelector
{
    /** Канонический словарь видов — значения kind фида H6313 и журнала H6095. */
    public const KINDS = ['обзорное', 'разовое', 'обычное'];

    /**
     * Поводы = предстоящие занятия видимых курсов, размеченные kind.
     *
     * @param  string|null  $kind  один из self::KINDS; null — все виды
     * @return Collection<int, array{title: string, course_slug: string, schedule_id: int, start: string, kind: string}>
     */
    public function select(?string $kind = null): Collection
    {
        if ($kind !== null && ! in_array($kind, self::KINDS, true)) {
            // Fail-closed: неизвестный вид — ошибка, а не молчаливое «пусто/всё».
            throw new InvalidArgumentException(
                'Unknown kind "'.$kind.'"; known kinds: '.implode(', ', self::KINDS).'.'
            );
        }

        $occasions = collect();

        Course::query()
            ->where('is_visible', true)
            ->orderBy('id')
            ->each(function (Course $course) use ($kind, $occasions): void {
                // Признак «разовое» считается на курс (та же семантика
                // irregular, что в фиде H6313), а не на строку.
                $irregular = collect(FullSchedulePost::forCourse($course))
                    ->every(fn (FullSchedulePost $post): bool => $post->cadence === null);

                foreach ($course->upcomingSchedules() as $schedule) {
                    $scheduleKind = $schedule->is_overview
                        ? 'обзорное'
                        : ($irregular ? 'разовое' : 'обычное');

                    if ($kind !== null && $scheduleKind !== $kind) {
                        continue;
                    }

                    $occasions->push([
                        'title' => $course->title,
                        'course_slug' => $course->slug,
                        'schedule_id' => (int) $schedule->getKey(),
                        'start' => $schedule->start?->toIso8601String(),
                        'kind' => $scheduleKind,
                    ]);
                }
            });

        return $occasions
            // Тот же дедуп, что в фиде H6313 (course slug#schedule_id):
            // строка, видимая через два курса (общая группа), — один повод.
            ->unique(fn (array $occasion): string => $occasion['course_slug'].'#'.$occasion['schedule_id'])
            ->sortBy([
                ['start', 'asc'],
                ['course_slug', 'asc'],
                ['schedule_id', 'asc'],
            ], SORT_REGULAR)
            ->values();
    }
}
