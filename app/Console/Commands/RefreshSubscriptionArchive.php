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
    protected $signature = 'subscription:refresh-archive
        {--dry-run : показать план без записи}
        {--backfill : одноразовый вход безрасписной спины-каталоги (format=recorded задолго старше окна); шедулер так НЕ делает}';

    protected $description = 'H3916: включить записные курсы в архив подписки спустя 6 месяцев после последнего занятия';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $backfill = (bool) $this->option('backfill');
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
                // Расписание чистится ретенцией (на проде строки с 2025-09):
                // спина-каталог 2024/25 доказательства даты не имеет вовсе.
                // format=recorded сам является маркером «поток завершён, записи
                // опубликованы» (H266), а такие курсы давным-давно старше
                // 6 месяцев. Вход — ТОЛЬКО явным --backfill, шедулер строг.
                if ($backfill) {
                    $this->line("join archive (backfill, no schedule evidence): {$course->id} {$course->title}");
                    if (! $dryRun) {
                        $course->forceFill([
                            'club_included' => true,
                            'subscription_archive_joined_at' => Carbon::today(),
                        ])->save();
                    }
                    $joined++;

                    continue;
                }

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
