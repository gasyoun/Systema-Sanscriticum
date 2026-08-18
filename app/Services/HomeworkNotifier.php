<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Resources\HomeworkSubmissionResource;
use App\Mail\HomeworkSubmittedMail;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use App\Support\HomeworkReviewPolicy;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Кому уходит весть о новой сданной работе (H1729).
 *
 * До гранта проверяющих получатель был ровно один — основной преподаватель
 * курса. Теперь их два класса:
 *
 *  - проверяющие подшефной группы с notify = true — колокольчик в админке,
 *    письмо и Telegram (каналы в config/homework.php);
 *  - преподаватель курса — письмо как раньше, НО если у группы есть активный
 *    проверяющий, персональные письма заменяются недельной сводкой
 *    (homework:reviewer-digest). Для групп без проверяющих не меняется ничего.
 */
class HomeworkNotifier
{
    public function submitted(HomeworkSubmission $submission, bool $isResubmission = false): void
    {
        // Курс формата «приём есть, проверки нет» (H3081): работа принимается и
        // хранится, но никого не дёргает. Проверка стоит ПЕРЕД `reviewersFor`,
        // потому что молчать надо в обе стороны — и проверяющим группы, и
        // преподавателю курса, которому иначе ушло бы письмо на каждую работу.
        if (HomeworkReviewPolicy::isUnreviewedSubmission($submission)) {
            return;
        }

        $reviewers = $this->reviewersFor($submission);

        foreach ($reviewers as $reviewer) {
            $this->notifyReviewer($reviewer, $submission, $isResubmission);
        }

        if ($reviewers->isEmpty() || ! $this->digestEnabled()) {
            $this->notifyCourseTeacher($submission, $isResubmission);
        }
    }

    /**
     * Проверяющие, подписанные на оповещения по группам этой работы.
     * Автор работы исключается: свои работы себе не анонсируем.
     *
     * @return Collection<int, User>
     */
    public function reviewersFor(HomeworkSubmission $submission): Collection
    {
        if (! config('homework.reviewers.enabled')) {
            return collect();
        }

        $groupIds = $submission->reviewGroupIds();
        if ($groupIds === []) {
            return collect();
        }

        return Group::whereIn('id', $groupIds)
            ->with('reviewers')
            ->get()
            ->flatMap(fn (Group $group) => $group->reviewers)
            ->filter(fn (User $reviewer) => (bool) $reviewer->pivot->notify)
            ->reject(fn (User $reviewer) => (int) $reviewer->id === (int) $submission->user_id)
            ->unique('id')
            ->values();
    }

    /**
     * Открылся приём ДЗ по уроку (H1764, D9) — студентам в мессенджеры.
     *
     * Получатели: активный состав группы урока; у общего урока (group_id пуст) —
     * все активные студенты групп курса. Каналы — из
     * `homework.auto_open.notify_channels`, письма среди них нет сознательно:
     * письмо после каждого занятия превращается в шум.
     *
     * Форма и обработка ошибок скопированы с `HomeworkService::pushReviewToMessengers`:
     * падение канала пишется в лог и НЕ роняет открытие урока.
     */
    public function opened(Lesson $lesson): void
    {
        $channels = (array) config('homework.auto_open.notify_channels', []);
        if ($channels === []) {
            return;
        }

        $course = $lesson->course;
        if (! $course) {
            return;
        }

        $lessonUrl = route('student.lesson', [$course->slug, $lesson->id]);
        $title = $lesson->title ? " «{$lesson->title}»" : '';
        $text = "📝 Открылось домашнее задание к уроку{$title}\n{$lessonUrl}";

        foreach ($this->studentsFor($lesson) as $student) {
            try {
                if (in_array('telegram', $channels, true) && $student->telegram_id) {
                    $student->sendTelegramMessage($text);
                }
                if (in_array('vk', $channels, true) && $student->vk_id) {
                    $student->sendVkMessage($text);
                }
            } catch (\Throwable $e) {
                Log::warning('Homework auto-open push failed', [
                    'lesson_id' => $lesson->id,
                    'user_id' => $student->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Кому уходит весть об открытии.
     *
     * @return Collection<int, User>
     */
    private function studentsFor(Lesson $lesson): Collection
    {
        if ($lesson->group_id) {
            return Group::with('activeUsers')
                ->find($lesson->group_id)
                ?->activeUsers
                ->values() ?? collect();
        }

        return Group::whereHas('courses', fn ($q) => $q->whereKey($lesson->course_id))
            ->with('activeUsers')
            ->get()
            ->flatMap(fn (Group $group) => $group->activeUsers)
            ->unique('id')
            ->values();
    }

    private function notifyReviewer(User $reviewer, HomeworkSubmission $submission, bool $isResubmission): void
    {
        $channels = (array) config('homework.reviewers.notify_channels', []);
        $title = $isResubmission ? 'Работа сдана повторно' : 'Новая работа на проверку';
        $body = trim(sprintf(
            '%s · %s',
            $submission->user?->name ?: 'Студент',
            $submission->lesson?->title ?: ($submission->course?->title ?: ''),
        ), ' ·');
        $reviewUrl = $this->reviewUrl($submission);

        if (in_array('database', $channels, true)) {
            Notification::make()
                ->title($title)
                ->body($body)
                ->icon('heroicon-o-pencil-square')
                ->actions([
                    Action::make('review')->label('Проверить')->url($reviewUrl),
                ])
                ->sendToDatabase($reviewer);
        }

        if (in_array('mail', $channels, true) && $reviewer->email) {
            Mail::to($reviewer->email)->send(new HomeworkSubmittedMail($submission, $reviewUrl, $isResubmission));
        }

        if (in_array('telegram', $channels, true) && $reviewer->telegram_id) {
            $reviewer->sendTelegramMessage("📝 <b>{$title}</b>\n".e($body)."\n{$reviewUrl}");
        }
    }

    private function notifyCourseTeacher(HomeworkSubmission $submission, bool $isResubmission): void
    {
        $email = $submission->course?->teacher?->email;

        if (! $email) {
            Log::info("HomeworkNotifier: у курса #{$submission->course_id} нет email преподавателя — уведомление о сдаче пропущено.");

            return;
        }

        Mail::to($email)->send(new HomeworkSubmittedMail($submission, $this->reviewUrl($submission), $isResubmission));
    }

    private function digestEnabled(): bool
    {
        return (bool) config('homework.reviewers.digest_enabled');
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
