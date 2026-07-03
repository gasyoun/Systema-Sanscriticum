<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\HomeworkFile;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
use App\Services\HomeworkDeadlineService;
use App\Services\HomeworkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HomeworkController extends Controller
{
    public function __construct(
        private HomeworkService $service,
        private HomeworkDeadlineService $deadlines,
    ) {}

    /**
     * Студент сохраняет черновик или отправляет домашнюю работу на проверку.
     */
    public function store(Request $request, string $courseSlug, int $lessonId)
    {
        $user = $request->user();
        $course = Course::where('slug', $courseSlug)->firstOrFail();
        $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);

        $this->ensureLessonAccessible($user, $course, $lesson);

        abort_unless((bool) $lesson->homework_enabled, 404, 'У этого урока нет домашнего задания.');

        $validated = $request->validate([
            'action' => ['required', 'in:draft,submit'],
            'body' => ['nullable', 'string', 'max:10000'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:30720', 'mimes:pdf,jpg,jpeg,png,heic,webp,mp3,m4a,ogg,wav,doc,docx,txt'],
        ]);

        $finalize = $validated['action'] === 'submit';

        // Существующую отправленную/принятую работу студент менять не может —
        // правки разрешены только в черновике и при возврате на доработку.
        $existing = HomeworkSubmission::where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        if ($existing && ! $existing->isEditableByStudent()) {
            return back()->with('error', 'Работа уже на проверке или принята — изменения недоступны.');
        }

        if ($reason = $this->deadlines->rejectReasonForSubmit($user, $lesson, $existing)) {
            return back()->with('error', $reason);
        }

        $uploaded = $request->file('files', []);

        if ($finalize && empty($validated['body']) && empty($uploaded)) {
            return back()->with('error', 'Добавьте текст ответа или прикрепите файл перед отправкой.');
        }

        $files = [];
        foreach ($uploaded as $file) {
            $safeName = $this->safeFilename($file->getClientOriginalName());
            $path = $file->storeAs("homework/{$user->id}/{$lesson->id}", Str::random(8).'_'.$safeName, 'local');

            $files[] = [
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
            ];
        }

        $this->service->recordSubmission($user, $lesson, $validated['body'] ?? null, $files, $finalize);

        return back()->with('success', $finalize
            ? 'Работа отправлена на проверку.'
            : 'Черновик сохранён.');
    }

    /**
     * Контролируемое скачивание файла домашки: владелец-студент,
     * преподаватель курса или админ. Файлы лежат на приватном диске local.
     */
    public function download(Request $request, HomeworkFile $file)
    {
        $user = $request->user();
        $submission = $file->comment?->submission;

        abort_if(! $submission, 404);

        $isOwner = $submission->user_id === $user->id;
        $isCourseTeacher = $user->teacher_id
            && optional($submission->course)->teacher_id === $user->teacher_id;
        $isAdmin = (bool) $user->is_admin;

        abort_unless($isOwner || $isCourseTeacher || $isAdmin, 403);

        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, $file->original_name);
    }

    /**
     * Зеркалит StudentController::ensureLessonAccessible — доступ к уроку
     * (free, персональный грант, либо оплачен full/block_X). Защита сдачи от
     * IDOR на чужие уроки.
     */
    private function ensureLessonAccessible($user, Course $course, Lesson $lesson): void
    {
        if ($lesson->is_free) {
            return;
        }

        // Персональный грант на урок (платное пробное — Payment::processTrial,
        // либо выданный куратором доступ к одному уроку). Плеер (StudentController)
        // пускает такого студента, поэтому и сдача ДЗ должна пускать — иначе урок
        // видно, а ДЗ 403 (money-core, H071 #16).
        if (LessonAccessGrant::userCanWatch($user, $lesson)) {
            return;
        }

        $unlocked = Payment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->paid()
            ->pluck('tariff')
            ->toArray();

        if (! $lesson->isUnlockedBy($unlocked)) {
            abort(403, 'Нет доступа к этому уроку.');
        }
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}\.\-_]+/u', '_', $name) ?? 'file';

        return mb_substr(trim($name, '_'), 0, 80) ?: 'file';
    }
}
