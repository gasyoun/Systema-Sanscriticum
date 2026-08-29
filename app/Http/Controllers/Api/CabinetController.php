<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
use App\Services\Membership\ClubEntitlement;
use App\Services\Membership\RecordingAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only эндпоинты кабинета для мобильного приложения. Доступ — те же
 * правила, что и в вебе (группы + оплаченные тарифы + разовые grant'ы).
 */
class CabinetController extends Controller
{
    /** Курсы студента с прогрессом прохождения. */
    public function courses(Request $request): JsonResponse
    {
        $user = $request->user();
        $groupIds = $user->groups->pluck('id');

        $courses = Course::query()
            ->where('is_active', true)
            ->whereHas('groups', fn ($q) => $q->whereIn('groups.id', $groupIds))
            ->with(['lessons:id,course_id'])
            ->get();

        $completedIds = $user->completedLessons()->pluck('lessons.id');

        $payload = $courses->map(function (Course $course) use ($completedIds): array {
            $lessonIds = $course->lessons->pluck('id');
            $total = $lessonIds->count();
            $completed = $lessonIds->intersect($completedIds)->count();

            return [
                'id' => $course->id,
                'slug' => $course->slug,
                'title' => $course->title,
                'lessons_total' => $total,
                'lessons_completed' => $completed,
                'percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
            ];
        })->values();

        return response()->json(['courses' => $payload]);
    }

    /** Уроки курса с пометками «пройден» и «закрыт». */
    public function lessons(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        $groupIds = $user->groups->pluck('id');

        // H2644: клуб — второе основание открыть курс, наравне с группой.
        $club = app(ClubEntitlement::class);

        $course = Course::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->whereHas('groups', fn ($g) => $g->whereIn('groups.id', $groupIds))
                ->orWhere(fn ($c) => $club->allows($user, 'recordings')
                    ? $c->where('club_included', true)
                    : $c->whereRaw('1 = 0')))
            ->firstOrFail();

        $clubCovers = $club->coversCourse($user, $course);

        $lessonsQuery = $course->lessons();
        if (! $clubCovers) {
            $lessonsQuery->forUserGroups($user);
        }
        $lessons = $lessonsQuery->orderBy('sort_order')->orderBy('created_at')->get();

        $unlockedTariffs = array_values(array_unique(array_merge(
            Payment::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->paid()
                ->pluck('tariff')
                ->all(),
            $club->extraTariffKeys($user, $course),
        )));
        $grantedLessonIds = LessonAccessGrant::userGrantedLessonIds($user, (int) $course->id);
        $completedIds = $user->completedLessons()->pluck('lessons.id')->all();
        $recordings = app(RecordingAccessPolicy::class);

        $payload = $lessons->map(function ($lesson) use ($user, $course, $recordings, $unlockedTariffs, $grantedLessonIds, $completedIds): array {
            $unlocked = $lesson->is_free
                || in_array($lesson->id, $grantedLessonIds, true)
                || $lesson->isUnlockedBy($unlockedTariffs)
                || $club->coversLesson($user, $course, $lesson);

            $recording = $recordings->decide(
                $user,
                $course,
                $lesson,
                in_array($lesson->id, $grantedLessonIds, true),
                'api_recording',
            );

            return [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'is_free' => (bool) $lesson->is_free,
                'is_completed' => in_array($lesson->id, $completedIds, true),
                'locked' => ! $unlocked,
                'recording_locked' => ! $recording->allowed,
                'recording_mode' => $recording->mode,
            ];
        })->values();

        return response()->json([
            'course' => ['slug' => $course->slug, 'title' => $course->title],
            'lessons' => $payload,
        ]);
    }
}
