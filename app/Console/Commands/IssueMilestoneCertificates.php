<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Models\MarketingSetting;
use App\Services\CertificateIssuedNotifier;
use App\Services\LessonMaterialTagger;
use App\Services\MilestoneCertificateIssuer;
use App\Services\MilestoneIssueTarget;
use Illuminate\Console\Command;

/**
 * Ежедневная автовыдача сертификатов/справок по вехам курсов: триггер вехи
 * сработал (конец блока / N-е занятие группы / урок учебника / занятия
 * материала) → документы оплатившим участникам + уведомление TG/VK/email.
 * Вся логика «кто получает» — в MilestoneCertificateIssuer; здесь гейт,
 * догон авторазметки материала, прогон и отчёт.
 */
class IssueMilestoneCertificates extends Command
{
    protected $signature = 'certificates:issue-milestones {--dry-run : Показать, кому выдалось бы, без записи и рассылки}';

    protected $description = 'Выдаёт сертификаты по созревшим вехам курсов и уведомляет студентов.';

    public function handle(
        MilestoneCertificateIssuer $issuer,
        CertificateIssuedNotifier $notifier,
        LessonMaterialTagger $tagger,
    ): int {
        $settings = MarketingSetting::cached();
        if (! $this->option('dry-run') && ! ($settings?->certificate_auto_issue_enabled ?? false)) {
            $this->info('Автовыдача сертификатов отключена в настройках — пропуск.');

            return self::SUCCESS;
        }

        // Догон авторазметки материала: стенограммы, пришедшие до создания
        // material-вехи, размечаются здесь (обычный путь — job после загрузки).
        if (! $this->option('dry-run')) {
            $tagger->catchUp();
        }

        $targets = $issuer->dueTargets();
        $issued = 0;

        foreach ($targets as $target) {
            if ($this->option('dry-run')) {
                foreach ($issuer->eligibleUsers($target->milestone, $target->group, $target->occurrence) as $user) {
                    $has = Certificate::query()
                        ->where('user_id', $user->id)
                        ->where('certificate_milestone_id', $target->milestone->id)
                        ->where('occurrence', $target->occurrence)
                        ->exists();
                    if (! $has) {
                        $issued++;
                        $this->line('[dry-run] '.$target->label()." → {$user->name} (#{$user->id})");
                    }
                }

                continue;
            }

            $issued += $issuer->issueForTarget($target)->count();
        }

        // Доотправка: документы вех без notified_at (включая упавшие вчера).
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

        $milestoneCount = $targets->map(fn (MilestoneIssueTarget $t) => $t->milestone->id)->unique()->count();
        $this->info("Вехи: {$milestoneCount}, целей: {$targets->count()}, выдано: {$issued}, уведомлено: {$notified}.");

        return self::SUCCESS;
    }
}
