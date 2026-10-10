<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\PromoCode;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Награды за итоговый тест мини-курса: персональный промокод на курс
 * грамматики + приглашение на ближайшее занятие «напевного» курса.
 *
 * Идемпотентность — детерминированный код промокода: PREP50-{user_id}
 * (firstOrCreate), повторная сдача теста новый код не плодит.
 */
final class CourseFinalRewards
{
    public static function promoCodeFor(User $user): PromoCode
    {
        $code = self::promoCodeName($user);

        return PromoCode::firstOrCreate(
            ['code' => $code],
            [
                'type' => 'percent',
                'value' => (float) config('mini_courses.promo_percent', 50),
                'course_id' => self::grammarCourseId(),
                'usage_limit' => 1,
                'used_count' => 0,
                'expires_at' => now()->addDays((int) config('mini_courses.promo_days_valid', 30)),
                'is_active' => true,
            ],
        );
    }

    public static function promoCodeName(User $user): string
    {
        return config('mini_courses.promo_code_prefix', 'PREP50').'-'.$user->id;
    }

    /** Ближайшее будущее занятие «напевного» курса — цель приглашения. */
    public static function nearestTrialEvent(): ?Schedule
    {
        $courseId = self::trialCourseId();
        if (! $courseId) {
            return null;
        }

        return Schedule::where('course_id', $courseId)
            ->where('start', '>=', now())
            ->orderBy('start')
            ->first();
    }

    public static function grammarCourse(): ?Course
    {
        return Course::find(self::grammarCourseId());
    }

    public static function grammarCourseId(): int
    {
        return (int) config('mini_courses.grammar_course_id', 450);
    }

    public static function trialCourseId(): int
    {
        return (int) config('mini_courses.trial_course_id', 451);
    }

    /** Приглашение показываем, только если есть куда звать. */
    public static function shouldInvite(User $user): bool
    {
        return self::nearestTrialEvent() !== null
            && ! $user->groups()
                ->where('groups.id', self::trialCourseId())
                ->exists();
    }

    /** Человекочитаемый код — только для показа владельцу. */
    public static function promoDisplayCode(User $user): string
    {
        return Str::upper(self::promoCodeName($user));
    }
}
