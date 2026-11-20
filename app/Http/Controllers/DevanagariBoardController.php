<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DevanagariBoard;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * H6327 — постоянная доска прописи (Excalidraw) в кабинете ученика.
 *
 * Замена webwhiteboard.com (терял все доски каждые 24 часа): сцена JSON
 * лежит в MySQL на пару «студент × занятие». Каждому студенту — только
 * свои доски; чей-то lesson_id без доступа к занятию → 404.
 */
class DevanagariBoardController extends Controller
{
    public function page(Request $request): View
    {
        $lesson = $this->resolveLesson($request);

        return view('propisi.board', [
            'lesson' => $lesson,
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $lesson = $this->resolveLesson($request);
        $board = $this->findBoard($lesson);

        return response()->json([
            'scene' => $board?->scene !== null ? json_decode((string) $board->scene, true) : null,
            'updated_at' => $board?->updated_at?->toIso8601String(),
            'lesson' => $lesson?->only(['id', 'title']),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $lesson = $this->resolveLesson($request);

        $validated = $request->validate([
            'scene' => ['required', 'array'],
            'scene.elements' => ['present', 'array'],
        ]);

        $payload = json_encode($validated['scene'], JSON_UNESCAPED_UNICODE);

        if ($payload === false || strlen($payload) > DevanagariBoard::SCENE_MAX_BYTES) {
            // Cap из миграционной заметки: сцена слишком велика — просим
            // вынести часть за пределы доски (копировать в новую), а не молча терять.
            return response()->json([
                'message' => 'Сцена слишком велика (лимит '.DevanagariBoard::SCENE_MAX_BYTES.' байт). Разделите доску или экспортируйте картинку.',
            ], 413);
        }

        $board = DevanagariBoard::query()->updateOrCreate(
            [
                'student_id' => (int) $request->user()->id,
                'lesson_id' => $lesson?->id,
            ],
            [
                'title' => $lesson !== null ? ('Прописи — '.$lesson->title) : 'Прописи',
                'scene' => $payload,
                'elements_count' => count($validated['scene']['elements'] ?? []),
            ],
        );

        return response()->json([
            'saved' => true,
            'updated_at' => $board->updated_at->toIso8601String(),
            'elements_count' => $board->elements_count,
        ]);
    }

    private function findBoard(?Lesson $lesson): ?DevanagariBoard
    {
        return DevanagariBoard::query()
            ->where('student_id', auth()->id())
            ->where('lesson_id', $lesson?->id)
            ->first();
    }

    private function resolveLesson(Request $request): ?Lesson
    {
        $lessonId = (int) $request->query('lesson', (string) $request->input('lesson', '0'));

        if ($lessonId <= 0) {
            return null;
        }

        $lesson = Lesson::find($lessonId);

        if ($lesson === null) {
            abort(404, 'Занятие не найдено.');
        }

        if (! $this->studentHasLessonAccess($request->user(), $lesson)) {
            abort(403, 'Нет доступа к этому занятию.');
        }

        return $lesson;
    }

    private function studentHasLessonAccess($user, Lesson $lesson): bool
    {
        if (method_exists($user, 'groups')) {
            $groupIds = $user->groups->pluck('id');

            if ($groupIds->isEmpty()) {
                return false;
            }

            return Lesson::query()
                ->where('lessons.id', $lesson->id)
                ->whereHas('course.groups', fn ($q) => $q->whereIn('groups.id', $groupIds))
                ->exists();
        }

        return false;
    }
}
