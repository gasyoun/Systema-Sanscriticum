<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\HomeworkComment;
use App\Models\HomeworkFile;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
use App\Services\HomeworkImagePdfService;
use App\Services\HomeworkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HomeworkController extends Controller
{
    public function __construct(private HomeworkService $service) {}

    /**
     * Студент сохраняет черновик или отправляет домашнюю работу на проверку.
     */
    public function store(Request $request, string $courseSlug, int $lessonId)
    {
        $user = $request->user();
        $course = Course::resolveBySlugOrFail($courseSlug);
        $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);

        $this->ensureLessonAccessible($user, $course, $lesson);

        // Серверный гейт приёма — через единственную точку правды на модели
        // (H1764). Без него закрытие приёма обходится прямым POST мимо витрины.
        abort_unless($lesson->homeworkOpenFor($user), 404, 'У этого урока нет домашнего задания.');

        $maxFiles = (int) config('homework.max_files', 10);
        $maxFileKb = (int) config('homework.max_file_kb', 30720);
        $totalMaxKb = (int) config('homework.total_max_kb', 92160);
        $extensions = implode(',', (array) config('homework.allowed_extensions', []));

        $validated = $request->validate([
            'action' => ['required', 'in:draft,submit'],
            'body' => ['nullable', 'string', 'max:10000'],
            'files' => [
                'nullable',
                'array',
                'max:'.$maxFiles,
                // Суммарный вес отправки: без этой проверки превышение ловит
                // не Laravel, а PHP/nginx — пустой страницей 413, на которой
                // теряется уже введённый текст ответа.
                function (string $attribute, $value, \Closure $fail) use ($request, $totalMaxKb) {
                    $total = 0;
                    foreach ($request->file('files', []) as $file) {
                        if ($file && $file->isValid()) {
                            $total += $file->getSize();
                        }
                    }

                    if ($total > $totalMaxKb * 1024) {
                        $fail(sprintf(
                            'Суммарный размер файлов — %s МБ, а можно не больше %s МБ. Отправьте работу в две части или сожмите видео.',
                            number_format($total / 1048576, 1, ',', ' '),
                            number_format($totalMaxKb / 1024, 0, ',', ' ')
                        ));
                    }
                },
            ],
            'files.*' => ['file', 'max:'.$maxFileKb, 'mimes:'.$extensions],
        ]);

        $finalize = $validated['action'] === 'submit';

        $existing = HomeworkSubmission::where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        // Accepted — только через возврат преподавателем; draft/submitted/needs_revision — можно.
        if ($existing && ! $existing->isEditableByStudent()) {
            return back()->with('error', 'Работа уже принята — изменения недоступны.');
        }

        // Черновик после сдачи убрал бы работу из очереди — запрещаем.
        if (! $finalize && $existing?->status === HomeworkSubmission::STATUS_SUBMITTED) {
            return back()->with('error', 'Работа уже на проверке. Чтобы изменить её, нажмите «Обновить работу».');
        }

        $uploaded = $request->file('files', []);

        if ($finalize && empty($validated['body']) && empty($uploaded)) {
            return back()->with('error', 'Добавьте текст ответа или прикрепите файл перед отправкой.');
        }

        // H3524: защита от повторной отправки при «зависшей» сдаче. Пока работа
        // стоит submitted, точный дубль последнего набора файлов не создаёт
        // ещё один комментарий-сдачу и не дёргает проверяющего второй раз —
        // студент получает внятное «уже приняты». Смешанный набор (старые
        // файлы + новый), текст без файлов и сдача после needs_revision
        // проходят как обычное обновление.
        if ($finalize && $existing?->status === HomeworkSubmission::STATUS_SUBMITTED && $uploaded !== []) {
            $lastSubmissionComment = $existing->comments()
                ->where('author_role', HomeworkComment::ROLE_STUDENT)
                ->where('type', HomeworkComment::TYPE_SUBMISSION)
                ->orderByDesc('id')
                ->with('files')
                ->first();

            if ($lastSubmissionComment !== null && $lastSubmissionComment->files->isNotEmpty()) {
                $incomingSignatures = collect($uploaded)
                    ->map(fn ($file) => $this->fileDuplicateSignature($file))
                    ->sort()
                    ->values()
                    ->all();

                $storedSignatures = $lastSubmissionComment->files
                    ->map(fn (HomeworkFile $file) => mb_strtolower($file->original_name).'|'.$file->size)
                    ->sort()
                    ->values()
                    ->all();

                if ($incomingSignatures === $storedSignatures) {
                    $when = $existing->last_activity_at
                        ? ' с '.$existing->last_activity_at->format('d.m в H:i')
                        : '';

                    return back()->with('error', 'Эти файлы уже приняты — работа на проверке'.$when.'. Повторная отправка не нужна.');
                }
            }
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

        $wasAlreadySubmitted = $existing
            && $existing->status === HomeworkSubmission::STATUS_SUBMITTED;

        $this->service->recordSubmission($user, $lesson, $validated['body'] ?? null, $files, $finalize);

        if (! $finalize) {
            $success = 'Черновик сохранён.';
        } elseif ($wasAlreadySubmitted) {
            $success = 'Работа обновлена — преподаватель увидит новое сообщение.';
        } else {
            $success = 'Работа отправлена на проверку.';
        }

        return back()->with('success', $success);
    }

    /**
     * Подпись файла для сверки дублей (H3524): имя + размер. Mime намеренно
     * вне подписи — один и тот же снимок iPhone может прийти с разным
     * клиентским mime (heic/heif), и ложный «не дубль» безопаснее ложного
     * блока, а совпадение имени и байтового размера достаточно точно.
     */
    private function fileDuplicateSignature($file): string
    {
        return mb_strtolower((string) $file->getClientOriginalName()).'|'.$file->getSize();
    }

    /**
     * Контролируемое скачивание файла домашки: владелец-студент или
     * штат (админ / препод курса / проверяющий группы). Файлы — диск local.
     */
    public function download(Request $request, HomeworkFile $file)
    {
        $user = $request->user();
        $file->loadMissing('comment.submission.course', 'comment.submission.lesson');
        $submission = $file->comment?->submission;

        abort_if(! $submission, 404);

        $isOwner = $submission->user_id === $user->id;
        $isStaff = $this->service->actorIsStaffFor($user, $submission);

        abort_unless($isOwner || $isStaff, 403);

        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, $file->original_name);
    }

    /**
     * Один PDF со всеми студенческими картинками сдачи (H2455).
     * Штат и владелец-студент. Нет PDF — 404 (нет картинок или сборка не удалась).
     *
     * По умолчанию inline (встроенный просмотр в админке без «скачать»).
     * ?download=1 — принудительное вложение.
     */
    public function downloadImagesPdf(Request $request, HomeworkSubmission $submission)
    {
        $user = $request->user();
        $submission->loadMissing(['course', 'lesson', 'user', 'comments.files']);

        $isOwner = (int) $submission->user_id === (int) $user->id;
        $isStaff = $this->service->actorIsStaffFor($user, $submission);
        abort_unless($isOwner || $isStaff, 403);

        $pdf = app(HomeworkImagePdfService::class);
        if (! $pdf->exists($submission)) {
            // Ленивая досборка: старые сдачи до деплоя или сбой при submit.
            $pdf->rebuildQuietly($submission);
        }
        abort_unless($pdf->exists($submission), 404, 'PDF с картинками пока нет.');

        $disk = HomeworkImagePdfService::DISK;
        $path = $pdf->pathFor($submission);
        $name = $pdf->downloadName($submission);

        if ($request->boolean('download')) {
            return Storage::disk($disk)->download($path, $name);
        }

        // inline: куратор открыл карточку — PDF рисуется в iframe сразу.
        return response()->file(
            Storage::disk($disk)->path($path),
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$name.'"',
            ],
        );
    }

    /**
     * Удалить залитый файл: студент — свой (пока editable); штат — любой
     * файл этой работы (H2120).
     */
    public function destroyFile(Request $request, HomeworkFile $file)
    {
        $user = $request->user();
        $file->loadMissing('comment.submission.course', 'comment.submission.lesson');
        $submission = $file->comment?->submission;
        abort_if(! $submission, 404);

        if ($this->service->actorIsStaffFor($user, $submission)) {
            $this->service->deleteFileAsStaff($file, $user);
        } else {
            $this->service->deleteStudentFile($file, $user);
        }

        return back()->with('success', 'Файл удалён из домашней работы.');
    }

    /**
     * Перенести студенческий файл в ДЗ другого урока того же курса (H2142).
     * Студент — свой editable; штат — любой студенческий файл работы.
     */
    public function moveFile(Request $request, HomeworkFile $file)
    {
        $validated = $request->validate([
            'lesson_id' => ['required', 'integer', 'exists:lessons,id'],
        ]);

        $targetLesson = Lesson::findOrFail($validated['lesson_id']);
        $target = $this->service->moveFileToLesson($file, $targetLesson, $request->user());

        if ($request->boolean('go_to_target') && $target->course) {
            return redirect()
                ->route('student.lesson', [$target->course->slug, $targetLesson->id])
                ->with('success', 'Файл перенесён в эту домашнюю работу.');
        }

        $title = $targetLesson->title ?: ('урок #'.$targetLesson->id);

        return back()->with('success', "Файл перенесён в «{$title}».");
    }

    /**
     * Студент удаляет своё сообщение в треде (текст + файлы), пока работа не принята.
     */
    public function destroyComment(Request $request, HomeworkComment $comment)
    {
        $this->service->deleteStudentComment($comment, $request->user());

        return back()->with('success', 'Сообщение удалено из переписки.');
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
