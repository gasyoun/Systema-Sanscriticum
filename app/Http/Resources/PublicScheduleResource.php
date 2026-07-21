<?php

namespace App\Http\Resources;

use App\Models\Category;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Публичный, встраиваемый фид расписания (H1427, wave 1b).
 *
 * ЭТО ГРАНИЦА БЕЗОПАСНОСТИ. Ресурс — жёсткий allowlist: наружу отдаются только
 * перечисленные ниже поля. Категорически НЕ отдаются `Schedule.link`
 * (accessor парсит URL из `description`, поэтому `description` тоже не отдаём),
 * `zoom_join_url`, `zoom_start_url` (host-only), любые числовые `id`/`*_id`,
 * а также любые PII преподавателя (email/phone/telegram/vk) или `telegram_chat_id`
 * группы. Идентификация наружу — только по слагам. Тест PublicScheduleFeedTest
 * закрепляет это и НЕ должен регрессировать (см. VERIFICATION §2 плана).
 *
 * Контроллер прикрепляет к каждому Schedule «владеющий» курс через
 * setRelation('course', $course), поэтому направление/преподаватель разрешаются
 * детерминированно от курса, даже когда у самой строки расписания course_id пуст
 * (только group_id).
 *
 * @mixin Schedule
 */
class PublicScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Course|null $course */
        $course = $this->course;
        /** @var Group|null $group */
        $group = $this->group;

        return [
            'title' => $this->title,
            'start' => $this->start?->toIso8601String(),
            'end' => $this->end?->toIso8601String(),
            // 1 = понедельник … 7 = воскресенье (для группировки по дням в виджете)
            'weekday' => $this->start?->dayOfWeekIso,
            'time' => $this->start?->format('H:i'),
            'course' => $course === null ? null : [
                'title' => $course->title,
                'slug' => $course->slug,
                'url' => route('shop.course.show', $course->slug),
            ],
            'directions' => $course === null
                ? []
                : $course->categories
                    ->map(fn (Category $category): array => [
                        'slug' => $category->slug,
                        'name' => $category->name,
                    ])
                    ->values()
                    ->all(),
            'teacher' => $course?->teacher?->name,
            'group' => $group === null ? null : [
                'name' => $group->name,
                'status' => $group->status,
                'seats_min' => $group->min_size,
                'is_recruited' => $group->isRecruited(),
            ],
        ];
    }
}
