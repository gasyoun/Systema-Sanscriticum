<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Mail\StudentLoginLinkMail;
use App\Models\User;
use App\Services\Messaging\MaxDeliveryChannel;
use App\Services\RevocationNotifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Доставляет студенту одноразовую ссылку для входа (H849), выданную кнопкой
 * «Разблокировать». Раньше ссылка только печаталась куратору в уведомлении и
 * пересылалась вручную — теперь она уходит студенту сама, по всем доступным
 * каналам: почта, Telegram, VK, Max. Если почта сломана (ровно тот случай, ради
 * которого magic-ссылка и существует), её отправку можно отключить тумблером.
 *
 * Возвращает per-канальный отчёт: `'sent'` / `'skipped:<причина>'` / `'failed:<причина>'`.
 * Куратор сразу видит в Filament-уведомлении, дошло ли, — и копия ссылки остаётся
 * у него на случай, когда не дошло никуда.
 *
 * Каналы шлются синхронно (кроме письма — оно в очередь `mailing`): иначе отчёт
 * «дошло/не дошло» невозможен. Каждый канал в своём try/catch — падение одного не
 * отменяет остальные. Тот же приём, что в {@see RevocationNotifier}.
 *
 * ВРЕМЕННЫЙ ПАРОЛЬ здесь не передаётся сознательно: пароль в переписке живёт
 * вечно, а ссылка — 24 часа и один клик. Пароль остаётся куратору для передачи
 * лично.
 */
class LoginLinkNotifier
{
    /**
     * @return array<string, string> ключи: telegram, email, vk, max
     */
    public function notify(User $student, string $loginLink, bool $sendEmail = true, bool $sendMessengers = true): array
    {
        $messengerText = $this->buildPlainText($loginLink);

        $report = [
            'telegram' => $sendMessengers ? $this->sendTelegram($student, $loginLink) : 'skipped:disabled',
            'email' => $sendEmail ? $this->sendEmail($student, $loginLink) : 'skipped:disabled',
            'vk' => $sendMessengers ? $this->sendVk($student, $messengerText) : 'skipped:disabled',
            'max' => $sendMessengers ? $this->sendMax($student, $messengerText) : 'skipped:disabled',
        ];

        Log::info('login_link_notify', [
            'user_id' => $student->id,
            'report' => $report,
        ]);

        return $report;
    }

    /**
     * Единственная проверка «письмо вообще может дойти»: пусто, служебная заглушка
     * `@no-email.com` или невалидный адрес. Публичная — её же использует
     * предупреждение в карточке студента, чтобы не держать вторую копию правила.
     */
    public static function hasDeliverableEmail(User $student): bool
    {
        $email = trim((string) $student->email);

        return $email !== ''
            && ! str_ends_with($email, '@no-email.com')
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Короткая строка для тела Filament-уведомления, чтобы обе кнопки
     * «Разблокировать» показывали отчёт одинаково.
     *
     * @param  array<string, string>  $report
     */
    public static function reportSummary(array $report): string
    {
        $labels = [
            'telegram' => 'Telegram',
            'email' => 'Почта',
            'vk' => 'VK',
            'max' => 'Max',
        ];

        $parts = [];
        foreach ($labels as $key => $label) {
            $parts[] = $label.': '.self::humanize($report[$key] ?? 'skipped:unknown');
        }

        return implode(' · ', $parts);
    }

    private static function humanize(string $status): string
    {
        return match (true) {
            $status === 'sent' => 'отправлено',
            $status === 'skipped:disabled' => 'выключено',
            $status === 'skipped:invalid_email' => 'некорректный адрес',
            str_starts_with($status, 'skipped:no_') => 'нет контакта',
            str_starts_with($status, 'failed:') => 'ошибка ('.substr($status, 7).')',
            default => $status,
        };
    }

    private function sendTelegram(User $student, string $loginLink): string
    {
        if (empty($student->telegram_id)) {
            return 'skipped:no_telegram_id';
        }

        $text = "🔑 <b>Ссылка для входа в личный кабинет</b>\n\n";
        $text .= "Мы открыли вам вход без пароля — просто нажмите на ссылку ниже.\n\n";
        $text .= "<a href='{$loginLink}'>Войти в личный кабинет</a>\n\n";
        $text .= '<i>Ссылка одноразовая и действует 24 часа. Если она уже не работает — напишите нам, вышлем новую.</i>';

        try {
            return $student->sendTelegramMessage($text) ? 'sent' : 'failed:api_error';
        } catch (\Throwable $e) {
            Log::warning('login_link_telegram_failed', ['user_id' => $student->id, 'error' => $e->getMessage()]);

            return 'failed:'.substr($e->getMessage(), 0, 80);
        }
    }

    private function sendEmail(User $student, string $loginLink): string
    {
        if (! self::hasDeliverableEmail($student)) {
            return 'skipped:invalid_email';
        }

        try {
            Mail::to($student->email)->queue(new StudentLoginLinkMail($student, $loginLink));

            return 'sent';
        } catch (\Throwable $e) {
            Log::warning('login_link_email_failed', ['user_id' => $student->id, 'error' => $e->getMessage()]);

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
            Log::warning('login_link_vk_failed', ['user_id' => $student->id, 'error' => $e->getMessage()]);

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
            Log::warning('login_link_max_failed', ['user_id' => $student->id, 'error' => $e->getMessage()]);

            return 'failed:'.substr($e->getMessage(), 0, 80);
        }
    }

    private function buildPlainText(string $loginLink): string
    {
        $text = "🔑 Ссылка для входа в личный кабинет\n\n";
        $text .= "Мы открыли вам вход без пароля — просто откройте ссылку ниже.\n\n";
        $text .= $loginLink."\n\n";
        $text .= 'Ссылка одноразовая и действует 24 часа. Если она уже не работает — напишите нам, вышлем новую.';

        return $text;
    }
}
