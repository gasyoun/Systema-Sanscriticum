<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
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

        $course = Course::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->whereHas('groups', fn ($q) => $q->whereIn('groups.id', $groupIds))
            ->firstOrFail();

        $lessons = $course->lessons()->forUserGroups($user)
            ->orderBy('sort_order')->orderBy('created_at')->get();

        $unlockedTariffs = Payment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->paid()
            ->pluck('tariff')
            ->all();
        $grantedLessonIds = LessonAccessGrant::userGrantedLessonIds($user, (int) $course->id);
        $completedIds = $user->completedLessons()->pluck('lessons.id')->all();

        $payload = $lessons->map(function ($lesson) use ($unlockedTariffs, $grantedLessonIds, $completedIds): array {
            $unlocked = $lesson->is_free
                || in_array($lesson->id, $grantedLessonIds, true)
                || $lesson->isUnlockedBy($unlockedTariffs);

            return [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'is_free' => (bool) $lesson->is_free,
                'is_completed' => in_array($lesson->id, $completedIds, true),
                'locked' => ! $unlocked,
            ];
        })->values();

        return response()->json([
            'course' => ['slug' => $course->slug, 'title' => $course->title],
            'lessons' => $payload,
        ]);
    }
}
