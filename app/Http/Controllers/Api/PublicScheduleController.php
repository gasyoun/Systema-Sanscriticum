<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicScheduleResource;
use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Публичный read-only фид расписания (H1427, wave 1b) для встраиваемого виджета.
 *
 * Отдаёт ближайшие занятия по всем видимым (`is_visible = true`) курсам, с
 * необязательными фильтрами `direction` (слаг категории) и `teacher` (имя
 * преподавателя). Переиспользует `Course::upcomingSchedules()` (тот же union
 * course_id / group_id, что и в кабинете) — по решению плана «profile before
 * choosing», без преждевременного переписывания рабочего метода.
 *
 * Граница безопасности — {@see PublicScheduleResource} (жёсткий allowlist полей).
 * Ответ кэшируется на 5 минут; ключ кэша включает параметры фильтра, поэтому
 * второй идентичный запрос в пределах TTL не выполняет запросов к БД.
 */
class PublicScheduleController extends Controller
{
    private const CACHE_TTL_MINUTES = 5;

    public function index(Request $request): JsonResponse
    {
        $direction = $this->stringOrNull($request->query('direction'));
        $teacher = $this->stringOrNull($request->query('teacher'));

        $cacheKey = 'public_schedule:'.md5(json_encode([
            'direction' => $direction,
            'teacher' => $teacher,
            // Флаг виджета записи (H3248) в ключе: включение/выключение
            // CRM_TRIAL_WIDGET_PUBLIC действует сразу, без окна устаревшего кэша.
            'trial_widget' => (bool) config('features.crm_trial_widget_public'),
        ]));

        $data = Cache::remember(
            $cacheKey,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn (): array => $this->buildFeed($direction, $teacher),
        );

        return response()->json(['data' => $data]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFeed(?string $direction, ?string $teacher): array
    {
        $courses = Course::query()
            ->where('is_visible', true)
            ->when($direction !== null, function ($query) use ($direction): void {
                $query->whereHas('categories', fn ($c) => $c->where('slug', $direction));
            })
            ->when($teacher !== null, function ($query) use ($teacher): void {
                $query->where(function ($sub) use ($teacher): void {
                    $sub->whereHas('teacher', fn ($t) => $t->where('name', $teacher))
                        ->orWhereHas('teachers', fn ($t) => $t->where('name', $teacher));
                });
            })
            ->with(['categories', 'teacher'])
            ->get();

        $schedules = collect();

        foreach ($courses as $course) {
            foreach ($course->upcomingSchedules() as $schedule) {
                // Прикрепляем «владеющий» курс из итерации, чтобы направление и
                // преподаватель разрешались детерминированно даже когда у самой
                // строки расписания course_id пуст (только group_id).
                $schedule->setRelation('course', $course);
                $schedules->push($schedule);
            }
        }

        $ordered = $schedules
            ->unique(fn (Schedule $schedule): string => $schedule->course->slug.'#'.$schedule->getKey())
            ->sortBy(fn (Schedule $schedule) => $schedule->start)
            ->values();

        return PublicScheduleResource::collection($ordered)->resolve();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
