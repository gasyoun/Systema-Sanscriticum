<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\User;
use App\Services\Membership\ClubEntitlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * H3308 — выдача контента урока, который больше нельзя держать на публичном
 * диске (/storage раздаёт файлы любому без авторизации):
 *   - стенограмма (transcripts/lesson-N.json) — текст платной лекции целиком;
 *   - материалы к уроку (lesson-materials/…);
 *   - справочные файлы ДЗ (homework-prompts/…).
 *
 * Гейт — ТА ЖЕ цепочка, что у плеера StudentController::showLesson:
 * грант на урок > клубное покрытие > видимость по группе курса,
 * затем бесплатный/оплаченный (is_free / isUnlockedBy). Не прошедшим — 404
 * (не 403): не раскрываем существование файла/урока.
 */
class GatedAssetController extends Controller
{
    public function transcript(Request $request, string $slug, int $lessonId): StreamedResponse
    {
        [$course, $lesson] = $this->resolveAccessible($request, $slug, $lessonId);

        $path = (string) $lesson->transcript_file;

        // Абсолютный URL (опубликованная лекция) — не наш файл.
        if ($path === '' || preg_match('#^https?://#i', $path) || str_starts_with($path, '/')) {
            abort(404);
        }

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->response($path, basename($path), [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'private, no-store',
                ]);
            }
        }

        abort(404);
    }

    public function material(Request $request, string $slug, int $lessonId, string $file): StreamedResponse
    {
        [$course, $lesson] = $this->resolveAccessible($request, $slug, $lessonId);

        return $this->streamListedFile((array) ($lesson->attachments ?? []), $file);
    }

    public function homeworkRef(Request $request, string $slug, int $lessonId, string $file): StreamedResponse
    {
        [$course, $lesson] = $this->resolveAccessible($request, $slug, $lessonId);

        return $this->streamListedFile((array) ($lesson->homework_attachments ?? []), $file);
    }

    /**
     * Файл отдаётся ТОЛЬКО если его basename числится в JSON-списке урока
     * (в БД хранится путь с директорией, в URL идёт basename — сверяем по
     * последнему сегменту; подмена имени/чужой путь не проходят).
     * Локальный диск — основное хранилище, `public` — легаси до прогона
     * lessons:privatize-gated-assets.
     */
    private function streamListedFile(array $listed, string $file): StreamedResponse
    {
        $stored = null;

        foreach ($listed as $item) {
            if (is_string($item) && basename($item) === $file) {
                $stored = $item;

                break;
            }
        }

        if ($stored === null) {
            abort(404);
        }

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($stored)) {
                return Storage::disk($disk)->response($stored);
            }
        }

        abort(404);
    }

    /**
     * Разрешение урока + гейт доступа плеера (StudentController::showLesson).
     * Возвращает [Course, Lesson] либо 404.
     *
     * @return array{0: Course, 1: Lesson}
     */
    private function resolveAccessible(Request $request, string $slug, int $lessonId): array
    {
        /** @var User $user */
        $user = $request->user();
        $course = Course::resolveBySlugOrFail($slug);
        $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);

        // Гейт — дословно как в плеере (StudentController::showLesson):
        // грант на урок / клубное покрытие / видимость по группе — основания
        // ПРОХОДА мимо группового фильтра; бесплатность/оплата — отдельное
        // ИЗ ТЕХ ЖЕ оснований условие (грант обходит и его).
        $hasLessonGrant = LessonAccessGrant::userCanWatch($user, $lesson);
        $clubCovers = app(ClubEntitlement::class)->coversCourse($user, $course);

        $visible = $hasLessonGrant || $clubCovers || $lesson->isVisibleToGroupsOf($user);
        $entitled = (bool) $lesson->is_free
            || $hasLessonGrant
            || $lesson->isUnlockedBy(StudentController::getUserUnlockedTariffs($user->id, $course->slug));

        abort_unless($visible && $entitled, 404);

        return [$course, $lesson];
    }
}
