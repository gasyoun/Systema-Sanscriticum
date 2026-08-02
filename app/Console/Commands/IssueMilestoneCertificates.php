<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Models\MarketingSetting;
use App\Services\CertificateIssuedNotifier;
use App\Services\MilestoneCertificateIssuer;
use Illuminate\Console\Command;

/**
 * Ежедневная автовыдача сертификатов по вехам курсов: блок end_block вехи
 * закончился (ends_at, не старше lookback-окна) → сертификаты оплатившим
 * участникам групп курса + уведомление TG/VK/email. Вся логика «кто получает» —
 * в MilestoneCertificateIssuer; здесь только гейт, прогон и отчёт.
 */
class IssueMilestoneCertificates extends Command
{
    protected $signature = 'certificates:issue-milestones {--dry-run : Показать, кому выдалось бы, без записи и рассылки}';

    protected $description = 'Выдаёт сертификаты по созревшим вехам курсов и уведомляет студентов.';

    public function handle(MilestoneCertificateIssuer $issuer, CertificateIssuedNotifier $notifier): int
    {
        $settings = MarketingSetting::cached();
        if (! $this->option('dry-run') && ! ($settings?->certificate_auto_issue_enabled ?? false)) {
            $this->info('Автовыдача сертификатов отключена в настройках — пропуск.');

            return self::SUCCESS;
        }

        $milestones = $issuer->dueMilestones();
        $issued = 0;

        foreach ($milestones as $milestone) {
            if ($this->option('dry-run')) {
                foreach ($issuer->eligibleUsers($milestone) as $user) {
                    $has = Certificate::query()
                        ->where('user_id', $user->id)
                        ->where('certificate_milestone_id', $milestone->id)
                        ->exists();
                    if (! $has) {
                        $issued++;
                        $this->line("[dry-run] {$milestone->course->title} / «{$milestone->title}» → {$user->name} (#{$user->id})");
                    }
                }

                continue;
            }

            $issued += $issuer->issueForMilestone($milestone)->count();
        }

        // Доотправка: сертификаты вех без notified_at (включая упавшие вчера).
        $notified = 0;
        if (! $this->option('dry-run')) {
            Certificate::query()
                ->whereNotNull('certificate_milestone_id')
                ->whereNull('notified_at')
                ->with('user')
                ->get()
                ->each(function (Certificate $certificate) use ($notifier, &$notified): void {
                    if ($notifier->notify($certificate)) {
                        $notified++;
                    }
                });
        }

        $this->info("Вехи: {$milestones->count()}, выдано: {$issued}, уведомлено: {$notified}.");

        return self::SUCCESS;
    }
}
