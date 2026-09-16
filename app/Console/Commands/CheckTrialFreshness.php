<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Services\Access\TelegramAdminNotifier;
use Illuminate\Console\Command;

/**
 * H4966: ежедневный монитор свежести пробного пина. Видимый курс с платным
 * пробным (`trial_price > 0`), чей `trial_schedule_id` указывает в прошлое
 * (или на удалённую строку), молча ломает виджет записи и NextIntroSession —
 * без монитора это читается как «воронка не работает» три недели подряд
 * (BLEED_AUDIT_TRIAL_FUNNEL_AND_CABINET_INVITES_16-09-2026.md).
 *
 * Репереуказание trial_schedule_id — действие человека в Filament (поле
 * «Пробное занятие»), агент прод-БД не трогает. Эта команда только сигналит.
 *
 *   php artisan trial:check-freshness            # лог + телеграм-алерт админам
 *   php artisan trial:check-freshness --quiet-ok  # без алерта, если нет протухших
 */
class CheckTrialFreshness extends Command
{
    protected $signature = 'trial:check-freshness';

    protected $description = 'Найти видимые курсы с протухшим пином пробного занятия (trial_schedule_id в прошлом) и алертнуть админов';

    public function handle(TelegramAdminNotifier $notifier): int
    {
        $stale = Course::query()
            ->where('is_visible', true)
            ->where('trial_price', '>', 0)
            ->whereNotNull('trial_schedule_id')
            ->with('trialSchedule')
            ->get()
            ->filter(fn (Course $course): bool => $course->hasStaleTrialPin())
            ->values();

        if ($stale->isEmpty()) {
            $this->info('Протухших пинов пробного занятия нет.');

            return self::SUCCESS;
        }

        $lines = $stale->map(function (Course $course): string {
            $date = $course->trialSchedule?->start?->toDateString() ?? 'строка удалена';

            return "  #{$course->id} «{$course->title}» (slug: {$course->slug}) — trial_schedule указывает на {$date}";
        });

        $this->warn("Протухших пинов: {$stale->count()}.");
        $this->line($lines->implode("\n"));

        $text = "⚠️ Протух пин пробного занятия ({$stale->count()} курс(ов)):\n\n"
            .$stale->map(fn (Course $c) => "• {$c->title} ({$c->slug})")->implode("\n")
            ."\n\nПереуказать «Пробное занятие» в Filament: /admin/courses.";

        $notifier->notifyAdmins($text);

        return self::SUCCESS;
    }
}
