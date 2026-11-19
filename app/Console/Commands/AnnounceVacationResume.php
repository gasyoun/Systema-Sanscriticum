<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\VacationResumeNotice;
use App\Services\VacationResumeService;
use Illuminate\Console\Command;

/**
 * H6343: каскад анонса возобновления занятий после каникул — T−7д и T−1д в
 * 20:00 МСК (каналы: ЛС + чат группы + email), гейт «первая живая строка после
 * паузы ≥21 день ИЛИ флаг каникул снят на этой неделе», плюс явка-алерт <50%
 * на первом занятии после паузы. Дедуп — vacation_resume_notices.
 */
class AnnounceVacationResume extends Command
{
    protected $signature = 'groups:announce-resume
        {--group= : Только одна группа (id или slug) — для точечной проверки}
        {--force : Немедленный анонс без гейта T−7/T−1 (ручная волна куратора)}
        {--dry-run : показать план без отправок и записей}';

    protected $description = 'Анонс возобновления занятий после каникул: каскад T−7д/T−1д 20:00 МСК + явка-алерт (H6343)';

    public function handle(VacationResumeService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $today = today();

        $groups = Group::query()
            ->where('status', '!=', 'archived')
            ->when($this->option('group') !== null, fn ($q) => $q->where(
                fn ($w) => $w->where('id', $this->option('group'))->orWhere('slug', $this->option('group')),
            ))
            ->orderBy('id')
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($groups as $group) {
            $resume = $service->resumeRow($group);
            if ($resume === null) {
                continue;
            }

            if ($force) {
                $phase = VacationResumeNotice::PHASE_MANUAL;
                $pause = $service->pauseDaysBefore($group, $resume);
            } else {
                $due = $service->duePhase($group, $today);
                if ($due === null) {
                    continue;
                }
                $phase = $due['phase'];
                $pause = $service->pauseDaysBefore($group, $resume);
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] группа %s (#%d): фаза %s, занятие %s, пауза %s дн.',
                    $group->name,
                    $group->id,
                    $phase,
                    $resume->start->format('d.m.Y H:i'),
                    $pause === null ? '—' : (string) $pause,
                ));
                $sent++;

                continue;
            }

            $result = $service->announce($group, $phase, $resume);

            if ($result['status'] === VacationResumeService::STATUS_SENT) {
                $this->info(sprintf(
                    'группа %s (#%d): фаза %s → получателей %d (каналы: %s)',
                    $group->name,
                    $group->id,
                    $phase,
                    $result['recipients'],
                    implode(', ', $result['channels']),
                ));
                $sent++;
            } elseif ($result['status'] === VacationResumeService::STATUS_DEDUP) {
                $skipped++;
            }
        }

        if ($dryRun) {
            $this->line('== dry-run: отправок не было ==');
        }

        $alerts = $service->attendanceAlerts($dryRun);
        if ($alerts > 0) {
            $this->info($dryRun
                ? "[dry-run] явка-алертов ушло бы: {$alerts}"
                : "явка-алертов куратору: {$alerts}");
        }

        $this->line("итог: волн {$sent}, дедуп-пропусков {$skipped}.");

        return self::SUCCESS;
    }
}
