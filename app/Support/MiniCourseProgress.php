<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\CourseQuizAttempt;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Воронка мини-курса «Подготовительная группа»: один ряд на студента
 * (запись на курс) с его прогрессом — пройденные уроки, попытки квизов
 * по этапам и финал, домашки, промокод и его погашение, источник.
 *
 * Всё считается bulk-запросами (по одному на сущность) — страница и
 * выгрузка не плодят N+1.
 */
final class MiniCourseProgress
{
    /** Прогресс кешируется на время рендера страницы (ключ = course_id). */
    private static ?Collection $cache = null;

    public static function course(): ?Course
    {
        return Course::where('slug', (string) config('mini_courses.slug'))->first();
    }

    public static function flushCache(): void
    {
        self::$cache = null;
    }

    /**
     * Ряды прогресса, ключ — user_id. Студенты без записи на курс не входят.
     *
     * @return Collection<int, object>
     */
    public static function rows(int $courseId): Collection
    {
        $enrolled = \DB::table('course_user')
            ->where('course_id', $courseId)
            ->pluck('created_at', 'user_id');

        if ($enrolled->isEmpty()) {
            return collect();
        }

        $userIds = $enrolled->keys()->all();

        $users = User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->map(function (User $u) use ($enrolled): object {
                return (object) [
                    'user' => $u,
                    'user_id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'signup_source' => $u->signup_source,
                    'registered_at' => $u->created_at,
                    'enrolled_at' => Carbon::parse($enrolled->get($u->id)),
                ];
            })
            ->keyBy('user_id');

        $lessonIds = Lesson::where('course_id', $courseId)->pluck('id');
        $lessonsTotal = $lessonIds->count();

        $completed = \DB::table('lesson_user')
            ->whereIn('user_id', $userIds)
            ->whereIn('lesson_id', $lessonIds)
            ->where('is_completed', true)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) AS n')
            ->pluck('n', 'user_id');

        $quizIds = Course::find($courseId)?->quizzes()->pluck('id', 'block_number') ?? collect();

        $attempts = CourseQuizAttempt::query()
            ->whereIn('course_quiz_id', $quizIds->values())
            ->whereIn('user_id', $userIds)
            ->orderBy('created_at')
            ->get();

        $hw = HomeworkSubmission::query()
            ->where('course_id', $courseId)
            ->whereIn('user_id', $userIds)
            ->get()
            ->groupBy('user_id');

        $prefix = (string) config('mini_courses.promo_code_prefix', 'PREP50');
        $promos = PromoCode::query()
            ->where('code', 'like', $prefix.'-%')
            ->whereIn('code', array_map(
                fn ($uid) => $prefix.'-'.$uid,
                $userIds,
            ))
            ->get()
            ->keyBy('code');

        $redeemedPromoIds = Payment::query()
            ->whereIn('promo_code_id', $promos->pluck('id'))
            ->paid()
            ->pluck('promo_code_id')
            ->unique();

        return $users->map(function (object $row) use ($lessonsTotal, $completed, $quizIds, $attempts, $hw, $promos, $redeemedPromoIds): object {
            $userAttempts = $attempts->where('user_id', $row->user_id);
            $quizByBlock = [];

            foreach ($quizIds as $block => $quizId) {
                $own = $userAttempts->where('course_quiz_id', $quizId);
                $best = $own->sortByDesc(fn ($a) => [$a->passed ? 1 : 0, $a->score, $a->id])->first();
                $quizByBlock[$block] = [
                    'attempts' => $own->count(),
                    'passed' => (bool) $best?->passed,
                    'score' => $best->score ?? null,
                    'total' => $best->total ?? null,
                    'last_at' => $own->max('created_at'),
                ];
            }

            $promo = $promos->get(config('mini_courses.promo_code_prefix', 'PREP50').'-'.$row->user_id);
            $submissions = $hw->get($row->user_id, collect());

            return (object) [
                'user' => $row->user,
                'user_id' => $row->user_id,
                'name' => $row->name,
                'email' => $row->email,
                'signup_source' => $row->signup_source,
                'registered_at' => $row->registered_at,
                'enrolled_at' => $row->enrolled_at,
                'lessons_completed' => (int) ($completed->get($row->user_id, 0)),
                'lessons_total' => $lessonsTotal,
                'quizzes' => $quizByBlock,
                'final_passed' => (bool) ($quizByBlock[6]['passed'] ?? false),
                'hw_submitted' => $submissions->whereNotIn('status', ['draft'])->count(),
                'hw_accepted' => $submissions->where('status', 'accepted')->count(),
                'promo_code' => $promo?->code,
                'promo_redeemed' => $promo !== null && $redeemedPromoIds->contains($promo->id),
            ];
        });
    }
}
