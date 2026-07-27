<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Resources\HomeworkSubmissionResource;
use App\Mail\HomeworkSubmittedMail;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\User;
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
