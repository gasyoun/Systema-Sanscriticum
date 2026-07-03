<?php

namespace App\Services;

use App\Mail\HomeworkReviewedMail;
use App\Mail\HomeworkSubmittedMail;
use App\Models\HomeworkComment;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

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
                && in_array($submission->status, [HomeworkSubmission::STATUS_NEEDS_CHANGES, HomeworkSubmission::LEGACY_STATUS_NEEDS_REVISION], true);

            $submission->course_id = $lesson->course_id;
            $submission->status = $finalize
                ? HomeworkSubmission::STATUS_SUBMITTED
                : HomeworkSubmission::STATUS_DRAFT;
            $submission->last_activity_at = now();
            if ($finalize) {
                $submission->submitted_at = now();
            }
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
                app(HomeworkNotificationService::class)->queue($submission, HomeworkNotificationService::EVENT_SUBMITTED);
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
        ?string $grade = null,
    ): HomeworkComment {
        if ($newStatus === HomeworkSubmission::STATUS_ACCEPTED && ! array_key_exists((string) $grade, HomeworkSubmission::gradeOptions())) {
            throw new InvalidArgumentException('Accepted homework requires a grade.');
        }

        $comment = DB::transaction(function () use ($submission, $reviewer, $newStatus, $body, $files, $grade) {
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
            if ($newStatus === HomeworkSubmission::STATUS_ACCEPTED) {
                $submission->accepted_at = now();
                $submission->grade = $grade;
            }
            $submission->save();

            $this->notifyStudent($submission, $comment);

            return $comment;
        });

        // Пуш в мессенджеры — после коммита: это синхронный HTTP, его нельзя
        // держать внутри транзакции. Письмо (notifyStudent) уходит в очередь.
        app(HomeworkNotificationService::class)->queue(
            $submission,
            $submission->status === HomeworkSubmission::STATUS_ACCEPTED
                ? HomeworkNotificationService::EVENT_ACCEPTED
                : HomeworkNotificationService::EVENT_RETURNED
        );

        return $comment;
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

    private function notifyTeacher(HomeworkSubmission $submission, bool $isResubmission = false): void
    {
        $email = $submission->course?->teacher?->email;

        if (! $email) {
            Log::info("HomeworkService: у курса #{$submission->course_id} нет email преподавателя — уведомление о сдаче пропущено.");

            return;
        }

        $reviewUrl = $this->reviewUrl($submission);
        Mail::to($email)->send(new HomeworkSubmittedMail($submission, $reviewUrl, $isResubmission));
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
        $resource = \App\Filament\Resources\HomeworkSubmissionResource::class;

        if (class_exists($resource)) {
            return $resource::getUrl('view', ['record' => $submission->id]);
        }

        return url('/admin');
    }
}
