<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Services\HomeworkNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendHomeworkNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly ?int $submissionId,
        public readonly string $event,
        public readonly ?int $userId = null,
        public readonly ?int $lessonId = null,
    ) {}

    public function handle(): void
    {
        $submission = $this->submissionId ? HomeworkSubmission::with(['user', 'lesson.course.teacher'])->find($this->submissionId) : null;
        $student = $submission?->user ?? ($this->userId ? User::find($this->userId) : null);
        $lesson = $submission?->lesson ?? ($this->lessonId ? Lesson::with('course.teacher')->find($this->lessonId) : null);

        if (! $student || ! $lesson) {
            return;
        }

        if (in_array($this->event, [
            HomeworkNotificationService::EVENT_RETURNED,
            HomeworkNotificationService::EVENT_ACCEPTED,
            HomeworkNotificationService::EVENT_OPENED,
            HomeworkNotificationService::EVENT_DEADLINE_NEAR,
            HomeworkNotificationService::EVENT_LOCKED,
        ], true)) {
            $this->sendToStudent($student, $this->studentText($student, $lesson, $submission));
        }

        if ($this->event === HomeworkNotificationService::EVENT_SUBMITTED && $submission) {
            $text = $this->teacherText($submission);
            $this->sendToTeacherUsers($submission, $text);
            $this->sendToHomeworkChats($text);
        }
    }

    private function sendToStudent(User $student, string $text): void
    {
        if ($student->telegram_id) {
            $student->sendTelegramMessage($text);
        }
        if ($student->vk_id) {
            $student->sendVkMessage(strip_tags($text));
        }
    }

    private function sendToTeacherUsers(HomeworkSubmission $submission, string $text): void
    {
        $teacherId = $submission->course?->teacher_id;
        if (! $teacherId) {
            return;
        }

        User::query()
            ->where('teacher_id', $teacherId)
            ->where(fn ($q) => $q->whereNotNull('telegram_id')->orWhereNotNull('vk_id'))
            ->each(function (User $teacher) use ($text): void {
                if ($teacher->telegram_id) {
                    $teacher->sendTelegramMessage($text);
                }
                if ($teacher->vk_id) {
                    $teacher->sendVkMessage(strip_tags($text));
                }
            });
    }

    private function sendToHomeworkChats(string $text): void
    {
        $settings = MarketingSetting::cached();

        $telegramChatId = (string) ($settings?->homework_telegram_chat_id ?? '');
        if ($telegramChatId !== '') {
            SendTelegramChatMessageJob::dispatch($telegramChatId, $text);
        }

        $vkPeerId = (string) ($settings?->homework_vk_peer_id ?? '');
        $vkToken = (string) config('services.vk.bot_token');
        if ($vkPeerId !== '' && $vkToken !== '') {
            $response = Http::asForm()->post('https://api.vk.com/method/messages.send', [
                'peer_id' => $vkPeerId,
                'random_id' => random_int(1, 2_147_483_647),
                'message' => strip_tags($text),
                'access_token' => $vkToken,
                'v' => '5.199',
            ]);

            if (! empty($response->json('error'))) {
                Log::warning('Homework VK group notification failed', ['response' => $response->json()]);
            }
        }
    }

    private function studentText(User $student, Lesson $lesson, ?HomeworkSubmission $submission): string
    {
        $lessonTitle = e($lesson->title);
        $url = $lesson->course ? route('student.lesson', [$lesson->course->slug, $lesson->id]) : url('/dvaram');

        return match ($this->event) {
            HomeworkNotificationService::EVENT_OPENED => "📝 <b>Открыта сдача домашней работы</b>\n\nУрок: <b>{$lessonTitle}</b>\n{$url}",
            HomeworkNotificationService::EVENT_DEADLINE_NEAR => "⏰ <b>Скоро срок сдачи домашней работы</b>\n\nУрок: <b>{$lessonTitle}</b>\n{$url}",
            HomeworkNotificationService::EVENT_RETURNED => "✍️ <b>Домашняя работа возвращена на доработку</b>\n\nУрок: <b>{$lessonTitle}</b>\nОткройте комментарии преподавателя:\n{$url}",
            HomeworkNotificationService::EVENT_ACCEPTED => "✅ <b>Домашняя работа принята</b>\n\nУрок: <b>{$lessonTitle}</b>\nОценка: <b>".e((string) ($submission?->gradeLabel() ?? $submission?->grade ?? '—'))."</b>\n{$url}",
            HomeworkNotificationService::EVENT_LOCKED => "🔒 <b>Сдача домашней работы закрыта</b>\n\nУрок: <b>{$lessonTitle}</b>. Если нужен дополнительный срок, напишите преподавателю.",
            default => "Домашняя работа: {$lessonTitle}\n{$url}",
        };
    }

    private function teacherText(HomeworkSubmission $submission): string
    {
        $student = e((string) ($submission->user?->name ?? ('#'.$submission->user_id)));
        $course = e((string) ($submission->course?->title ?? '—'));
        $lesson = e((string) ($submission->lesson?->title ?? '—'));
        $reviewUrl = \App\Filament\Resources\HomeworkSubmissionResource::getUrl('view', ['record' => $submission->id]);

        return "📝 <b>Новая домашняя работа</b>\n\n"
            ."Студент: <b>{$student}</b>\n"
            ."Курс: <b>{$course}</b>\n"
            ."Урок: <b>{$lesson}</b>\n"
            .$reviewUrl;
    }
}
