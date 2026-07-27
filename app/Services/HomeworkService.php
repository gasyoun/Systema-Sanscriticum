<?php

namespace App\Services;

use App\Filament\Resources\HomeworkSubmissionResource;
use App\Mail\HomeworkReviewedMail;
use App\Models\HomeworkComment;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
        return DB::transaction(function () use ($student, $lesson, $body, $files, $finalize) {
            $submission = HomeworkSubmission::firstOrNew([
                'user_id' => $student->id,
                'lesson_id' => $lesson->id,
            ]);
            // Пересдача: работа уже возвращалась на доработку и студент шлёт её заново.
            // Фиксируем до перезаписи статуса — после save() прежнее значение теряется.
            $isResubmission = $submission->exists
                && $submission->status === HomeworkSubmission::STATUS_NEEDS_REVISION;

            $submission->course_id = $lesson->course_id;
            $submission->status = $finalize
                ? HomeworkSubmission::STATUS_SUBMITTED
                : HomeworkSubmission::STATUS_DRAFT;
            $submission->last_activity_at = now();
            $submission->save();

            $comment = $submission->comments()->create([
                'author_id' => $student->id,
                'author_role' => HomeworkComment::ROLE_STUDENT,
                'type' => HomeworkComment::TYPE_SUBMISSION,
                'body' => $body,
            ]);

            $this->attachFiles($comment, $files);

            if ($finalize) {
                $this->notifyTeacher($submission, $isResubmission);
            }

            return $submission;
        });
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
