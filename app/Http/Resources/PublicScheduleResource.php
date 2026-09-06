<?php

namespace App\Http\Resources;

use App\Models\Category;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Support\TrialBookToken;
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
 * H3248: при включённом `crm_trial_widget_public` строка пробного занятия курса
 * (`Course.trial_schedule_id`) дополнительно помечается `bookable: true` и несёт
 * HMAC `book_token` ({@see TrialBookToken}) — по-прежнему БЕЗ числовых id.
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

        $bookable = (bool) config('features.crm_trial_widget_public')
            && $course !== null
            && (int) $course->trial_schedule_id === (int) $this->getKey();

        $row = [
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
            // Новизна для анонсов «только новые курсы» (MG 31-08-2026):
            // new / repeat / no_repeat / usual — виджет фильтрует по нему.
            'novelty' => $course?->novelty ?? 'usual',
            'group' => $group === null ? null : [
                'name' => $group->name,
                'status' => $group->status,
                'seats_min' => $group->min_size,
                'is_recruited' => $group->isRecruited(),
                // H3790: каникулы — флаг + дата выхода (nullable date, без PII)
                'is_on_vacation' => (bool) $group->is_on_vacation,
                'vacation_resume_date' => $group->vacation_resume_date?->toDateString(),
                // H4253: ведущий преподаватель в личном отпуске на дату этого
                // занятия — только bool, без имени/PII преподавателя.
                'is_teacher_on_vacation' => $this->start !== null && $group->teachersOnVacationCovering($this->start),
            ],
        ];

        // Ключ book_token появляется ТОЛЬКО у записываемой строки при
        // включённом флаге: «нет book_token в JSON» при выключенном — тест.
        $row['bookable'] = $bookable;
        if ($bookable) {
            $row['book_token'] = TrialBookToken::for((int) $this->getKey());
        }

        return $row;
    }
}
