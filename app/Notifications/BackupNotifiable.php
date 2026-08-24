<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Support\Facades\Log;
use Spatie\Backup\Notifications\Notifiable;

/**
 * H3312: fail-closed получатель backup-уведомлений.
 *
 * Адрес берётся из единого канонического источника
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
}
