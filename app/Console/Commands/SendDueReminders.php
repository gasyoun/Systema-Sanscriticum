<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScheduledReminder;
use Illuminate\Console\Command;

class SendDueReminders extends Command
{
    protected $signature = 'reminders:send-due';

    protected $description = 'Отправить разовые напоминания студентам (ScheduledReminder), у которых наступило scheduled_for. Снимает риск того, что куратор забудет напомнить вручную.';

    public function handle(): int
    {
        $sent = 0;
        $skipped = 0;

        ScheduledReminder::query()->pending()->with('user')->each(function (ScheduledReminder $reminder) use (&$sent, &$skipped) {
            if ($reminder->send()) {
                $reminder->update(['sent_at' => now()]);
                $sent++;
            } else {
                // Нет доступного канала (студент не привязал TG/VK и email невалиден) —
                // отмечаем как отправленное, чтобы не зациклиться на нём каждые 15 минут.
                $reminder->update(['sent_at' => now()]);
                $skipped++;
            }
        });

        $this->info("Разовые напоминания: отправлено {$sent}, пропущено (нет канала) {$skipped}.");

        return self::SUCCESS;
    }
}
