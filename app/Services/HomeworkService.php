<?php

namespace App\Services;

use App\Filament\Resources\HomeworkSubmissionResource;
use App\Jobs\BuildHomeworkImagesPdfJob;
use App\Mail\HomeworkReviewedMail;
use App\Models\HomeworkComment;
use App\Models\HomeworkFile;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class HomeworkService
{
    /**
     * Студент сохраняет/отправляет работу.
     *
     * @param  array<int, array{disk:string,path:string,original_name:string,size:int,mime:?string}>  $files
     */
    public function recordSubmission(
        User $student,
        Lesson $lesson,
        ?string $body,
        array $files,
        bool $finalize,
    ): HomeworkSubmission {
        $isResubmission = false;

        $submission = DB::transaction(function () use ($student, $lesson, $body, $files, $finalize, &$isResubmission) {
            $submission = HomeworkSubmission::firstOrNew([
                'user_id' => $student->id,
                'lesson_id' => $lesson->id,
            ]);
            // Повторное уведомление: доработка после needs_revision ИЛИ правка уже
            // сданной (submitted) работы — преподавателю нужна пометка «повторно».
            // Фиксируем до перезаписи статуса — после save() прежнее значение теряется.
            $priorStatus = $submission->exists ? $submission->status : null;
            $isResubmission = in_array($priorStatus, [
                HomeworkSubmission::STATUS_NEEDS_REVISION,
                HomeworkSubmission::STATUS_SUBMITTED,
            ], true);

            $submission->course_id = $lesson->course_id;
            if ($finalize) {
                $submission->status = HomeworkSubmission::STATUS_SUBMITTED;
            } elseif ($priorStatus === HomeworkSubmission::STATUS_SUBMITTED) {
                // Нельзя «откатить» сданную в черновик — работа исчезнет из очереди проверки.
                // Контроллер не должен пускать draft здесь; страховка на уровне сервиса.
                $submission->status = HomeworkSubmission::STATUS_SUBMITTED;
            } else {
                $submission->status = HomeworkSubmission::STATUS_DRAFT;
            }
            $submission->last_activity_at = now();
            $submission->save();

            $comment = $submission->comments()->create([
                'author_id' => $student->id,
                'author_role' => HomeworkComment::ROLE_STUDENT,
                'type' => HomeworkComment::TYPE_SUBMISSION,
                'body' => $body,
            ]);

            $this->attachFiles($comment, $files);

            return $submission;
        });

        // H3095, вариант B: сборка PDF ушла в очередь, уведомление НЕ зависит
        // от её исхода. Порядок «сборка, потом уведомление» и был дефектом —
        // обязательное стояло за необязательным и наследовало все его способы
        // умереть, включая фатальный OOM, который `try/catch` не ловит.
        // Постановка в очередь идёт первой только чтобы дать сборке фору
        // (вложение чаще успевает к письму); от её успеха уведомление
        // отвязано в `queueImagesPdfRebuild()`. Обоснование развилки —
        // docs/DECISION_HOMEWORK_IMAGES_PDF_OFF_REQUEST_PATH_2026.md.
        if ($finalize) {
            $this->queueImagesPdfRebuild($submission);
            $this->notifyTeacher($submission, $isResubmission);
        }

        return $submission;
    }

    /**
     * Студент удаляет свой вложенный файл, пока работу ещё можно править
     * (draft / submitted / needs_revision). Принятые работы и файлы преподавателя
     * не трогаем — история проверки остаётся целой.
     *
     * Сам файл с диска уходит, в треде остаётся служебная отметка
     * (имя файла + время в created_at) — удаления не бесследны.
     */
    public function deleteStudentFile(HomeworkFile $file, User $student): void
    {
        $submission = DB::transaction(function () use ($file, $student) {
            $file->loadMissing('comment.submission');
            $comment = $file->comment;
            $submission = $comment?->submission;

            abort_if(! $submission, 404);
            abort_unless($submission->user_id === $student->id, 403);
            abort_unless($submission->isEditableByStudent(), 403);
            abort_unless($comment->author_role === HomeworkComment::ROLE_STUDENT, 403);
            abort_unless((int) $comment->author_id === (int) $student->id, 403);

            $label = $this->fileDeletionLabel($file);
            $this->unlinkAndDeleteFileRow($file);

            $this->recordDeletionNote(
                $submission,
                $student,
                HomeworkComment::ROLE_STUDENT,
                "Удалён файл «{$label}».",
            );

            $submission->last_activity_at = now();
            $submission->save();

            return $submission;
        });

        $this->queueImagesPdfRebuild($submission);
    }

    /**
     * Штат (админ / препод курса / проверяющий группы) удаляет любой файл
     * работы — в т.ч. ошибочно залитое ДЗ, которое студент уже не может
     * править (accepted) или не видит в кабинете. H2120.
     *
     * В треде — служебная отметка с именем файла (время = created_at).
     */
    public function deleteFileAsStaff(HomeworkFile $file, User $staff): void
    {
        $file->loadMissing('comment.submission.course', 'comment.submission.lesson');
        $submission = $file->comment?->submission;

        abort_if(! $submission, 404);
        abort_unless($this->actorIsStaffFor($staff, $submission), 403);

        DB::transaction(function () use ($file, $submission, $staff) {
            $label = $this->fileDeletionLabel($file);
            $this->unlinkAndDeleteFileRow($file);

            $this->recordDeletionNote(
                $submission,
                $staff,
                $staff->isAdminLike()
                    ? HomeworkComment::ROLE_ADMIN
                    : HomeworkComment::ROLE_TEACHER,
                "Удалён файл «{$label}».",
            );

            $submission->last_activity_at = now();
            $submission->save();
        });

        $this->queueImagesPdfRebuild($submission);
    }

    /**
     * Штат стирает все студенческие вложения (диск + строки). Опционально
     * возвращает работу в «На доработку», чтобы студент мог залить заново.
     * В треде — список имён удалённых файлов (не только счётчик).
     *
     * @return int сколько файлов удалено
     */
    public function clearStudentFiles(
        HomeworkSubmission $submission,
        User $staff,
        bool $reopenForRevision = true,
    ): int {
        abort_unless($this->actorIsStaffFor($staff, $submission), 403);

        $submission->loadMissing('comments.files');

        $deleted = DB::transaction(function () use ($submission, $staff, $reopenForRevision) {
            $names = [];

            foreach ($submission->comments as $comment) {
                if ($comment->author_role !== HomeworkComment::ROLE_STUDENT) {
                    continue;
                }
                foreach ($comment->files as $file) {
                    $names[] = $this->fileDeletionLabel($file);
                    $this->unlinkAndDeleteFileRow($file);
                }
            }

            $deleted = count($names);

            if ($reopenForRevision && $submission->status !== HomeworkSubmission::STATUS_DRAFT) {
                $submission->status = HomeworkSubmission::STATUS_NEEDS_REVISION;
                $submission->reviewed_by = $staff->id;
                $submission->reviewed_at = now();
            }

            $submission->last_activity_at = now();
            $submission->save();

            if ($deleted === 0) {
                $body = 'Файлов студента не было — приём открыт на правку.';
            } else {
                $list = collect($names)->map(fn (string $n) => '• '.$n)->implode("\n");
                $body = "Удалены залитые файлы студента ({$deleted}):\n{$list}\nМожно прикрепить заново.";
            }

            $this->recordDeletionNote(
                $submission,
                $staff,
                $staff->isAdminLike()
                    ? HomeworkComment::ROLE_ADMIN
                    : HomeworkComment::ROLE_TEACHER,
                $body,
                $reopenForRevision ? HomeworkSubmission::STATUS_NEEDS_REVISION : null,
            );

            return $deleted;
        });

        // Картинок больше нет — combined PDF тоже убираем (job удалит старый).
        $this->queueImagesPdfRebuild($submission);

        return $deleted;
    }

    /**
     * Перенести один студенческий файл в ДЗ другого урока (тот же студент, тот же курс).
     * H2142: залито не туда по ошибке — вместо удаления + повторной загрузки.
     *
     * Студент: пока source/target editable. Штат: любой студенческий файл.
     * На диске путь обновляется на homework/{user}/{target_lesson}/…; в обоих
     * тредах — служебные отметки.
     */
    public function moveFileToLesson(HomeworkFile $file, Lesson $targetLesson, User $actor): HomeworkSubmission
    {
        $file->loadMissing('comment.submission.course', 'comment.submission.lesson', 'comment.submission.user');
        $comment = $file->comment;
        $source = $comment?->submission;

        abort_if(! $source, 404);
        abort_unless($comment->author_role === HomeworkComment::ROLE_STUDENT, 403);
        abort_if((int) $targetLesson->id === (int) $source->lesson_id, 422, 'Уже этот урок.');
        abort_unless((bool) $targetLesson->homework_enabled, 422, 'У целевого урока нет ДЗ.');
        abort_unless((int) $targetLesson->course_id === (int) $source->course_id, 422, 'Только внутри того же курса.');

        $isStaff = $this->actorIsStaffFor($actor, $source);
        $isOwner = (int) $source->user_id === (int) $actor->id;
        abort_unless($isStaff || $isOwner, 403);

        if ($isOwner && ! $isStaff) {
            abort_unless($source->isEditableByStudent(), 403);
            abort_unless((int) $comment->author_id === (int) $actor->id, 403);
        }

        $studentId = (int) $source->user_id;

        $target = DB::transaction(function () use ($file, $comment, $source, $targetLesson, $actor, $isStaff, $studentId) {
            $target = HomeworkSubmission::firstOrNew([
                'user_id' => $studentId,
                'lesson_id' => $targetLesson->id,
            ]);
            $target->course_id = $targetLesson->course_id;

            if (! $isStaff && $target->exists && ! $target->isEditableByStudent()) {
                abort(403, 'Целевая работа уже принята — перенос недоступен.');
            }

            if (! $target->exists || $target->status === HomeworkSubmission::STATUS_DRAFT) {
                $target->status = HomeworkSubmission::STATUS_SUBMITTED;
            }

            $target->last_activity_at = now();
            $target->save();

            $disk = $file->disk ?: 'local';
            $oldPath = (string) $file->path;
            if ($oldPath !== '' && Storage::disk($disk)->exists($oldPath)) {
                $base = basename($oldPath);
                $newPath = "homework/{$studentId}/{$targetLesson->id}/{$base}";
                if ($newPath !== $oldPath) {
                    Storage::disk($disk)->move($oldPath, $newPath);
                    $file->path = $newPath;
                }
            }

            $targetComment = $target->comments()->create([
                'author_id' => $comment->author_id,
                'author_role' => HomeworkComment::ROLE_STUDENT,
                'type' => HomeworkComment::TYPE_SUBMISSION,
                'body' => null,
            ]);

            $file->comment_id = $targetComment->id;
            $file->save();

            $label = $this->fileDeletionLabel($file);
            $fromTitle = $source->lesson?->title ?: ('урок #'.$source->lesson_id);
            $toTitle = $targetLesson->title ?: ('урок #'.$targetLesson->id);

            $role = $isStaff
                ? ($actor->isAdminLike() ? HomeworkComment::ROLE_ADMIN : HomeworkComment::ROLE_TEACHER)
                : HomeworkComment::ROLE_STUDENT;

            $this->recordDeletionNote(
                $source,
                $actor,
                $role,
                "Файл «{$label}» перенесён в урок «{$toTitle}».",
            );
            $this->recordDeletionNote(
                $target,
                $actor,
                $role,
                "Файл «{$label}» перенесён из урока «{$fromTitle}».",
            );

            $source->last_activity_at = now();
            $source->save();

            return $target->fresh(['comments.files', 'lesson', 'course', 'user']);
        });

        $this->queueImagesPdfRebuild($source);
        $this->queueImagesPdfRebuild($target);

        return $target;
    }

    /**
     * Штат: перенести все студенческие файлы работы в ДЗ другого урока.
     *
     * @return int сколько файлов перенесено
     */
    public function moveAllStudentFiles(
        HomeworkSubmission $source,
        Lesson $targetLesson,
        User $staff,
    ): int {
        abort_unless($this->actorIsStaffFor($staff, $source), 403);
        $source->loadMissing('comments.files');

        $moved = 0;
        foreach ($source->comments as $comment) {
            if ($comment->author_role !== HomeworkComment::ROLE_STUDENT) {
                continue;
            }
            foreach ($comment->files->all() as $file) {
                $this->moveFileToLesson($file, $targetLesson, $staff);
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * Админ / преподаватель курса / проверяющий группы (H1729).
     * Совпадает с HomeworkSubmissionResource::canReview, без UI-слоя Filament.
     */
    public function actorIsStaffFor(User $actor, HomeworkSubmission $submission): bool
    {
        if ($actor->isAdminLike()) {
            return true;
        }

        if (! $actor->isTeacher()) {
            return false;
        }

        $submission->loadMissing('course', 'lesson');

        if ($actor->teacher_id && optional($submission->course)->teacher_id === $actor->teacher_id) {
            return true;
        }

        if ((int) $submission->user_id === (int) $actor->id) {
            return false;
        }

        foreach ($submission->reviewGroupIds() as $groupId) {
            if ($actor->canReviewGroup($groupId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Студент удаляет своё сообщение в треде целиком (текст + вложения),
     * пока работу ещё можно править. Вердикты и служебные отметки
     * (type=message) не удаляются — иначе стирается аудит.
     */
    public function deleteStudentComment(HomeworkComment $comment, User $student): void
    {
        DB::transaction(function () use ($comment, $student) {
            $comment->loadMissing(['submission', 'files']);
            $submission = $comment->submission;

            abort_if(! $submission, 404);
            abort_unless($submission->user_id === $student->id, 403);
            abort_unless($submission->isEditableByStudent(), 403);
            abort_unless($comment->author_role === HomeworkComment::ROLE_STUDENT, 403);
            abort_unless((int) $comment->author_id === (int) $student->id, 403);
            // review / служебные отметки / чужие реплики не трогаем
            abort_if($comment->type === HomeworkComment::TYPE_REVIEW, 403);
            abort_if($comment->type === HomeworkComment::TYPE_MESSAGE, 403);

            $names = $comment->files->map(fn (HomeworkFile $f) => $this->fileDeletionLabel($f))->all();
            $hadBody = filled($comment->body);

            foreach ($comment->files as $file) {
                $this->unlinkAndDeleteFileRow($file);
            }

            $comment->delete();

            $parts = ['Удалено сообщение из переписки.'];
            if ($hadBody) {
                $parts[] = 'Был текст ответа.';
            }
            if ($names !== []) {
                $list = collect($names)->map(fn (string $n) => '• '.$n)->implode("\n");
                $parts[] = "Файлы:\n{$list}";
            }
            $this->recordDeletionNote(
                $submission,
                $student,
                HomeworkComment::ROLE_STUDENT,
                implode(' ', $parts),
            );

            $submission->last_activity_at = now();
            $submission->save();
        });
    }

    /**
     * Служебная строка в треде: кто удалил + что (время = created_at).
     * Сами байты файла с диска уже сняты — остаётся только эта отметка.
     */
    private function recordDeletionNote(
        HomeworkSubmission $submission,
        User $actor,
        string $role,
        string $body,
        ?string $newStatus = null,
    ): HomeworkComment {
        return $submission->comments()->create([
            'author_id' => $actor->id,
            'author_role' => $role,
            'type' => HomeworkComment::TYPE_MESSAGE,
            'body' => $body,
            'new_status' => $newStatus,
        ]);
    }

    private function fileDeletionLabel(HomeworkFile $file): string
    {
        $name = trim((string) $file->original_name) ?: 'файл';
        // Без переносов/управляющих — одна строка в треде.
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = mb_substr($name, 0, 120);

        $size = $file->size > 0 ? ' · '.$file->humanSize() : '';

        return $name.$size;
    }

    private function unlinkAndDeleteFileRow(HomeworkFile $file): void
    {
        $disk = $file->disk ?: 'local';
        $path = $file->path;
        $file->delete();

        if ($path && Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    /**
     * Преподаватель/админ выносит вердикт.
     *
     * @param  array<int, array{disk:string,path:string,original_name:string,size:int,mime:?string}>  $files
     */
    public function recordReview(
        HomeworkSubmission $submission,
        User $reviewer,
        string $newStatus,
        ?string $body,
        array $files = [],
    ): HomeworkComment {
        $comment = DB::transaction(function () use ($submission, $reviewer, $newStatus, $body, $files) {
            $role = $reviewer->is_admin
                ? HomeworkComment::ROLE_ADMIN
                : HomeworkComment::ROLE_TEACHER;

            $comment = $submission->comments()->create([
                'author_id' => $reviewer->id,
                'author_role' => $role,
                'type' => HomeworkComment::TYPE_REVIEW,
                'body' => $body,
                'new_status' => $newStatus,
            ]);

            $this->attachFiles($comment, $files);

            $submission->status = $newStatus;
            $submission->reviewed_by = $reviewer->id;
            $submission->reviewed_at = now();
            $submission->last_activity_at = now();
            $submission->save();

            $this->notifyStudent($submission, $comment);

            return $comment;
        });

        // Пуш в мессенджеры — после коммита: это синхронный HTTP, его нельзя
        // держать внутри транзакции. Письмо (notifyStudent) уходит в очередь.
        $this->pushReviewToMessengers($submission, $comment);

        return $comment;
    }

    /**
     * Мгновенно уведомить студента в привязанный мессенджер о вердикте по ДЗ.
     * Письмо уходит отдельно (notifyStudent); здесь — Telegram/VK, чтобы студент
     * узнал сразу, а не только из почты.
     */
    private function pushReviewToMessengers(HomeworkSubmission $submission, HomeworkComment $review): void
    {
        $user = $submission->user;
        if (! $user || (! $user->telegram_id && ! $user->vk_id)) {
            return;
        }

        $accepted = $submission->status === HomeworkSubmission::STATUS_ACCEPTED;
        $lessonTitle = $submission->lesson?->title;
        $where = $lessonTitle ? " «{$lessonTitle}»" : '';

        $lessonUrl = url('/login');
        if ($submission->course && $submission->lesson) {
            $lessonUrl = route('student.lesson', [$submission->course->slug, $submission->lesson_id]);
        }

        $text = $accepted
            ? "✅ Ваша домашняя работа{$where} принята! Поздравляем 🎉\n{$lessonUrl}"
            : "✍️ Преподаватель вернул работу{$where} на доработку. Откройте урок и посмотрите комментарии:\n{$lessonUrl}";

        try {
            if ($user->telegram_id) {
                $user->sendTelegramMessage($text);
            }
            if ($user->vk_id) {
                $user->sendVkMessage($text);
            }
        } catch (\Throwable $e) {
            Log::warning('Homework review push failed', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, array{disk:string,path:string,original_name:string,size:int,mime:?string}>  $files
     */
    private function attachFiles(HomeworkComment $comment, array $files): void
    {
        foreach ($files as $file) {
            $comment->files()->create([
                'disk' => $file['disk'] ?? 'local',
                'path' => $file['path'],
                'original_name' => $file['original_name'],
                'size' => $file['size'] ?? 0,
                'mime' => $file['mime'] ?? null,
            ]);
        }
    }

    /**
     * Поставить пересборку `combined-images.pdf` в очередь (H3095).
     *
     * До 18-08-2026 сборка шла прямо здесь, на пути запроса. Она держит в
     * памяти base64 всех страниц, и на php-fpm с его 128M исчерпание памяти —
     * ФАТАЛЬНАЯ ошибка, которую `try/catch` внутри `rebuildQuietly()` не ловит:
     * падал весь POST сдачи, а `notifyTeacher()` за сборкой не выполнялся
     * (H3092 / H3095, [FINDINGS §483]).
     *
     * `try/catch` здесь — не про сборку (она теперь на воркере), а про саму
     * постановку в очередь: недоступный Redis или драйвер `sync` в чужой среде
     * не должны утащить за собой уведомление проверяющего. Это и есть то, что
     * делает обещание «уведомление не зависит от сборки» безусловным.
     */
    private function queueImagesPdfRebuild(?HomeworkSubmission $submission): void
    {
        if ($submission === null) {
            return;
        }

        try {
            BuildHomeworkImagesPdfJob::dispatch((int) $submission->id);
        } catch (\Throwable $e) {
            Log::warning('HomeworkImagePdf rebuild dispatch failed', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Маршрутизация вести о сдаче вынесена в HomeworkNotifier (H1729): получателей
     * стало двое — преподаватель курса и проверяющие подшефной группы.
     */
    private function notifyTeacher(HomeworkSubmission $submission, bool $isResubmission = false): void
    {
        app(HomeworkNotifier::class)->submitted($submission, $isResubmission);
    }

    private function notifyStudent(HomeworkSubmission $submission, HomeworkComment $review): void
    {
        $email = $submission->user?->email;

        if (! $email) {
            return;
        }

        $lessonUrl = url('/login');
        if ($submission->course && $submission->lesson) {
            $lessonUrl = route('student.lesson', [$submission->course->slug, $submission->lesson_id]);
        }

        Mail::to($email)->send(new HomeworkReviewedMail($submission, $review, $lessonUrl));
    }

    private function reviewUrl(HomeworkSubmission $submission): string
    {
        $resource = HomeworkSubmissionResource::class;

        if (class_exists($resource)) {
            return $resource::getUrl('view', ['record' => $submission->id]);
        }

        return url('/admin');
    }
}
