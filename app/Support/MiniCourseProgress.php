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

    /**
     * Детальная воронка одного студента для модалки на странице прогресса:
     * все уроки, все попытки квизов, домашки, промокод и его погашение.
     *
     * @return array<string, mixed>|null
     */
    public static function details(int $courseId, int $userId): ?array
    {
        $course = Course::find($courseId);
        $user = User::find($userId);

        if ($course === null || $user === null) {
            return null;
        }

        $enrolled = \DB::table('course_user')
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->value('created_at');

        // Уроки по порядку с отметками завершения (дата — из pivot).
        $lessons = Lesson::where('course_id', $courseId)
            ->orderBy('sort_order')
            ->get(['id', 'title', 'block_number']);
        $done = \DB::table('lesson_user')
            ->where('user_id', $userId)
            ->whereIn('lesson_id', $lessons->pluck('id'))
            ->where('is_completed', true)
            ->pluck('updated_at', 'lesson_id');

        $lessonRows = $lessons->map(fn (Lesson $l): array => [
            'title' => $l->title,
            'block' => (int) $l->block_number,
            'completed' => $done->has($l->id),
            'date' => $done->has($l->id)
                ? Carbon::parse($done->get($l->id))->timezone(config('app.timezone'))->format('d.m.y')
                : null,
        ])->all();

        // Все попытки квизов курса по порядку времени.
        $attempts = CourseQuizAttempt::query()
            ->join('course_quizzes', 'course_quizzes.id', '=', 'course_quiz_attempts.course_quiz_id')
            ->where('course_quizzes.course_id', $courseId)
            ->where('course_quiz_attempts.user_id', $userId)
            ->orderBy('course_quiz_attempts.created_at')
            ->get([
                'course_quizzes.block_number',
                'course_quizzes.title',
                'course_quiz_attempts.score',
                'course_quiz_attempts.total',
                'course_quiz_attempts.passed',
                'course_quiz_attempts.created_at',
            ])
            ->map(fn ($a): array => [
                'stage' => (int) $a->block_number,
                'title' => (string) $a->title,
                'score' => (int) $a->score,
                'total' => (int) $a->total,
                'passed' => (bool) $a->passed,
                'date' => Carbon::parse($a->created_at)->timezone(config('app.timezone'))->format('d.m.y H:i'),
            ])->all();

        // Домашки с названиями уроков.
        $hw = HomeworkSubmission::query()
            ->join('lessons', 'lessons.id', '=', 'homework_submissions.lesson_id')
            ->where('homework_submissions.course_id', $courseId)
            ->where('homework_submissions.user_id', $userId)
            ->orderBy('homework_submissions.created_at')
            ->get([
                'lessons.title AS lesson',
                'homework_submissions.status',
                'homework_submissions.created_at',
            ])
            ->map(fn ($h): array => [
                'lesson' => (string) $h->lesson,
                'status' => (string) $h->status,
                'date' => Carbon::parse($h->created_at)->timezone(config('app.timezone'))->format('d.m.y'),
            ])->all();

        // Промокод + погашение (paid-платёж с этим промо).
        $promo = PromoCode::where('code', (string) config('mini_courses.promo_code_prefix', 'PREP50').'-'.$userId)->first();
        $promoRedemption = null;

        if ($promo !== null) {
            $promoRedemption = Payment::query()
                ->where('promo_code_id', $promo->id)
                ->where('status', 'paid')
                ->get(['amount', 'created_at'])
                ->map(fn ($p): array => [
                    'amount' => (float) $p->amount,
                    'date' => Carbon::parse($p->created_at)->timezone(config('app.timezone'))->format('d.m.y'),
                ])
                ->first();
        }

        return [
            'name' => $user->name,
            'email' => $user->email,
            'signup_source' => $user->signup_source,
            'registered_at' => $user->created_at?->timezone(config('app.timezone'))->format('d.m.y'),
            'enrolled_at' => $enrolled !== null
                ? Carbon::parse($enrolled)->timezone(config('app.timezone'))->format('d.m.y')
                : null,
            'lessons' => $lessonRows,
            'attempts' => $attempts,
            'homework' => $hw,
            'promo' => $promo === null ? null : [
                'code' => $promo->code,
                'created' => $promo->created_at?->timezone(config('app.timezone'))->format('d.m.y'),
                'expires' => $promo->expires_at?->timezone(config('app.timezone'))->format('d.m.y'),
                'redeemed' => $promoRedemption,
            ],
        ];
    }
}
