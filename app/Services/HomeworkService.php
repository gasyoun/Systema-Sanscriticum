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
                $this->notifyTeacher($submission);
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
        return DB::transaction(function () use ($submission, $reviewer, $newStatus, $body, $files) {
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

    private function notifyTeacher(HomeworkSubmission $submission): void
    {
        $email = $submission->course?->teacher?->email;

        if (! $email) {
            Log::info("HomeworkService: у курса #{$submission->course_id} нет email преподавателя — уведомление о сдаче пропущено.");

            return;
        }

        $reviewUrl = $this->reviewUrl($submission);
        Mail::to($email)->send(new HomeworkSubmittedMail($submission, $reviewUrl));
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
