<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\AccessRevokedMail;
use App\Models\Course;
use App\Models\User;
use App\Services\Messaging\MaxDeliveryChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Дублирует уведомление об отзыве доступа по всем доступным каналам:
 * Telegram, email, VK, Max. Если в РФ заблокирован Telegram, шансы хотя бы
 * одного канала достучаться до студента возрастают. SMS пока вне scope —
 * добавляется отдельным методом, когда у проекта появится SMS-провайдер.
 *
 * Возвращает per-канальный отчёт: `'sent'` / `'skipped:no_id'` / `'failed:reason'`.
 * Менеджер сразу видит в Filament-уведомлении, дошло ли сообщение.
 */
class RevocationNotifier
{
    /**
     * @return array<string, string>
     */
    public function notify(User $student, Course $course, ?string $reasonNote = null): array
    {
        $courseTitle = (string) ($course->title ?? 'курс');
        $loginUrl = url('/login');

        $messengerText = $this->buildPlainText($courseTitle, $loginUrl, $reasonNote);

        $report = [
            'telegram' => $this->sendTelegram($student, $courseTitle, $loginUrl, $reasonNote),
            'email' => $this->sendEmail($student, $course, $reasonNote),
            'vk' => $this->sendVk($student, $messengerText),
            'max' => $this->sendMax($student, $messengerText),
        ];

        Log::info('access_revoked_notify', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'report' => $report,
        ]);

        return $report;
    }

    private function sendTelegram(User $student, string $courseTitle, string $loginUrl, ?string $reasonNote): string
    {
        if (empty($student->telegram_id)) {
            return 'skipped:no_telegram_id';
        }

        $text = "🔒 <b>Доступ временно закрыт</b>\n\n";
        $text .= "Доступ к курсу <b>«{$courseTitle}»</b> приостановлен. ";
        $text .= "После оплаты он откроется снова.\n\n";
        if ($reasonNote) {
            $text .= 'Комментарий менеджера: <i>'.e($reasonNote)."</i>\n\n";
        }
        $text .= "<a href='{$loginUrl}'>Перейти в личный кабинет</a>";

        try {
            return $student->sendTelegramMessage($text) ? 'sent' : 'failed:api_error';
        } catch (\Throwable $e) {
            Log::warning('revocation_telegram_failed', ['user_id' => $student->id, 'error' => $e->getMessage()]);

            return 'failed:'.substr($e->getMessage(), 0, 80);
        }
    }

    private function sendEmail(User $student, Course $course, ?string $reasonNote): string
    {
        if (empty($student->email)) {
            return 'skipped:no_email';
        }

        try {
            Mail::to($student->email)->queue(new AccessRevokedMail($student, $course, $reasonNote));

            return 'sent';
        } catch (\Throwable $e) {
            Log::warning('revocation_email_failed', ['user_id' => $student->id, 'error' => $e->getMessage()]);

            return 'failed:'.substr($e->getMessage(), 0, 80);
        }
    }

    private function sendVk(User $student, string $text): string
    {
        if (empty($student->vk_id)) {
            return 'skipped:no_vk_id';
        }

        try {
            return $student->sendVkMessage($text) ? 'sent' : 'failed:api_error';
        } catch (\Throwable $e) {
            Log::warning('revocation_vk_failed', ['user_id' => $student->id, 'error' => $e->getMessage()]);

            return 'failed:'.substr($e->getMessage(), 0, 80);
        }
    }

    private function sendMax(User $student, string $text): string
    {
        if (empty($student->max_user_id)) {
            return 'skipped:no_max_id';
        }

        try {
            app(MaxDeliveryChannel::class)->sendText((string) $student->max_user_id, $text);

            return 'sent';
        } catch (\Throwable $e) {
            Log::warning('revocation_max_failed', ['user_id' => $student->id, 'error' => $e->getMessage()]);

            return 'failed:'.substr($e->getMessage(), 0, 80);
        }
    }

    private function buildPlainText(string $courseTitle, string $loginUrl, ?string $reasonNote): string
    {
        $text = "🔒 Доступ временно закрыт\n\n";
        $text .= "Доступ к курсу «{$courseTitle}» приостановлен. После оплаты он откроется снова.\n\n";
        if ($reasonNote) {
            $text .= "Комментарий менеджера: {$reasonNote}\n\n";
        }
        $text .= "Личный кабинет: {$loginUrl}";

        return $text;
    }
}
