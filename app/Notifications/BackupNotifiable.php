<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Support\Facades\Log;
use Spatie\Backup\Notifications\Notifiable;
use Throwable;

/**
 * H3312: fail-closed получатель backup-уведомлений.
 *
 * Адрес берется из единого канонического источника
 * config('services.admin.email') (= env ADMIN_EMAIL). Пусто -> mail-канал
 * отключается: возвращаем пустой массив, на котором Laravel MailChannel
 * делает no-op (см. Channels\MailChannel::send), и пишем warning в лог -
 * никаких исключений, никакой отправки «в никуда».
 *
 * Родительский Notifiable читает backup.notifications.mail.to, но spatie
 * валидирует это поле через filter_var и бросает InvalidConfig на пустой
 * строке - поэтому там стоит синтаксически валидный плейсхолдер, который
 * НИКОГДА не используется как реальный адресат (см. config/backup.php).
 */
class BackupNotifiable extends Notifiable
{
    /** @return list<string> */
    public function routeNotificationForMail(): string|array
    {
        $adminEmail = trim((string) config('services.admin.email'));

        if ($adminEmail === '') {
            Log::warning('backup.notifications: ADMIN_EMAIL is not configured - mail notifications skipped.');

            return [];
        }

        return [$adminEmail];
    }

    /**
     * H6540: спати запускает уведомления СИНХРОННО внутри backup-джобы
     * (EventHandler::subscribe -> notify; у BackupWasSuccessful это происходит
     * прямо в try-блоке copyToBackupDestinations). Исключение канала — прод
     * 12-10-2026 02:10: SMTP «Network is unreachable» в ночном окне — вылетает
     * из event(), ловится чужим catch как «Copying zip failed» и красит весь
     * backup:run в exit 1, хотя зип и local-копия уже отгружены. Тем же болеет
     * и backup:clean (02:30:51, «Cleanup failed because: SMTP»). Поэтому
     * сбой КАНАЛА уведомлений проглатывается в лог: backup важнее письма о
     * нём; когда SMTP жив, письмо админу уходит как раньше (H3312 не тронут).
     */
    public function notify($instance)
    {
        try {
            parent::notify($instance);
        } catch (Throwable $exception) {
            Log::error('backup.notifications: канал уведомлений упал — проглочено, чтобы не ронять backup:run/clean', [
                'notification' => $instance::class,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
