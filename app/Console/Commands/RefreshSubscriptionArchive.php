<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * H3916: 6-месячное окно эксклюзивности подписки «в записи».
 *
 * Завершённый поток (format=recorded) попадает в архив подписки только
 * через 6 месяцев после последнего занятия по расписанию. Ручной раскладки
 * нет: шедулер ставит club_included=true (+ фиксирует дату входа), и полка
 * ClubEntitlement открывает курс подписчикам.
 *
 * Курс без расписания окном не открывается (нет доказательства даты
 * завершения) — попадает в отчёт как ручной остаток. Уже включённые
 * (2 ручных курса) не трогаются.
 *
 * Расписание: ежедневно (Kernel::schedule).
 */
class RefreshSubscriptionArchive extends Command
{
    protected $signature = 'subscription:refresh-archive {--dry-run : показать план без записи}';

    protected $description = 'H3916: включить записные курсы в архив подписки спустя 6 месяцев после последнего занятия';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subMonths(6);

        $candidates = Course::query()
            ->where('format', 'recorded')
            ->where('is_visible', true)
            ->where('club_included', false)
            ->get();

        $joined = 0;
        $notYet = 0;
        $noSchedule = 0;

        foreach ($candidates as $course) {
            $last = $course->streamLastSessionAt();

            if ($last === null) {
                $noSchedule++;
                $this->line("skip (no schedule evidence): {$course->id} {$course->title}");

                continue;
            }

            if ($last->greaterThan($cutoff)) {
                $notYet++;

                continue;
            }

            $this->line("join archive: {$course->id} {$course->title} (last session {$last->toDateString()})");
            if (! $dryRun) {
                $course->forceFill([
                    'club_included' => true,
                    'subscription_archive_joined_at' => Carbon::today(),
                ])->save();
            }
            $joined++;
        }

        $this->info("archive refresh: joined={$joined} not_due_yet={$notYet} no_schedule={$noSchedule}"
            .($dryRun ? ' (DRY-RUN)' : ''));

        return self::SUCCESS;
    }
}
