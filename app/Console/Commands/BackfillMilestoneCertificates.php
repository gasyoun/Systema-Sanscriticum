<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Services\CertificateIssuedNotifier;
use App\Services\MilestoneCertificateIssuer;
use App\Services\MilestoneIssueTarget;
use Illuminate\Console\Command;

/**
 * Разовый бэкафилл: проходит ВСЮ историю по всем вехам (без lookback-окна) и
 * выдаёт всё причитающееся за прошлые годы. Запускается руками, в Kernel не
 * стоит, гейт certificate_auto_issue_enabled игнорирует.
 *
 * Уведомления — opt-in (--notify): без него созданным документам сразу
 * проставляется notified_at, иначе ближайший ночной прогон
 * certificates:issue-milestones разослал бы сотни писем за прошлые годы.
 */
class BackfillMilestoneCertificates extends Command
{
    protected $signature = 'certificates:backfill-milestones
        {--dry-run : Показать, кому выдалось бы, без записи}
        {--notify : Разослать уведомления по выданному (по умолчанию НЕ слать)}
        {--course= : Только этот course_id}';

    protected $description = 'Разовая выдача сертификатов за всю историю по настроенным вехам (без lookback-окна).';

    public function handle(MilestoneCertificateIssuer $issuer, CertificateIssuedNotifier $notifier): int
    {
        $courseId = $this->option('course') !== null ? (int) $this->option('course') : null;

        $targets = $issuer->dueTargets(ignoreLookback: true)
            ->when($courseId, fn ($c) => $c->filter(
                fn (MilestoneIssueTarget $t) => (int) $t->milestone->course_id === $courseId
            ))
            ->values();

        if ($targets->isEmpty()) {
            $this->info('Созревших целей нет — выдавать нечего.');

            return self::SUCCESS;
        }

        // Сводка и подтверждение перед записью.
        $planned = [];
        foreach ($targets as $target) {
            foreach ($issuer->eligibleUsers($target->milestone, $target->group, $target->occurrence) as $user) {
                $has = Certificate::query()
                    ->where('user_id', $user->id)
                    ->where('certificate_milestone_id', $target->milestone->id)
                    ->where('occurrence', $target->occurrence)
                    ->exists();
                if (! $has) {
                    $planned[] = [$target, $user];
                    $this->line(($this->option('dry-run') ? '[dry-run] ' : '').$target->label()." → {$user->name} (#{$user->id})");
                }
            }
        }

        $this->info('Итого к выдаче: '.count($planned).' (целей: '.$targets->count().').');

        if ($this->option('dry-run') || $planned === []) {
            return self::SUCCESS;
        }

        if (! $this->confirm('Выдать перечисленные документы?')) {
            $this->info('Отменено.');

            return self::SUCCESS;
        }

        $issued = 0;
        $notified = 0;
        foreach ($targets as $target) {
            foreach ($issuer->issueForTarget($target, force: true) as $certificate) {
                $issued++;
                if ($this->option('notify')) {
                    if ($notifier->notify($certificate)) {
                        $notified++;
                    }
                } else {
                    // Гасим доотправку ночной команды: бэкафилл за прошлые годы
                    // без явного --notify писем слать не должен.
                    $certificate->forceFill(['notified_at' => now()])->save();
                }
            }
        }

        $this->info("Выдано: {$issued}, уведомлено: {$notified}.");

        return self::SUCCESS;
    }
}
