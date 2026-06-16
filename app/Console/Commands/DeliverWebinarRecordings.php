<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Services\Leads\LeadStepMailer;
use Illuminate\Console\Command;

/**
 * Рассылает лидам письмо со ссылкой на запись прошедшего вебинара.
 * Триггер — админ заполнил webinar_recording_url у лендинга (а не таймер от
 * webinar_date: запись готова позже эфира). Идемпотентность — за LeadStepMailer
 * (LeadStepEmail UNIQUE(lead_id, step)). Запуск каждые 15 минут (Kernel::schedule).
 */
final class DeliverWebinarRecordings extends Command
{
    protected $signature = 'webinar:deliver-recordings';

    protected $description = 'Разослать лидам письмо со ссылкой на запись вебинара';

    public function handle(LeadStepMailer $mailer): int
    {
        $landings = LandingPage::whereNotNull('webinar_recording_url')
            ->whereNotNull('webinar_date')
            ->where('webinar_date', '<', now())
            ->get();

        $sent = 0;

        foreach ($landings as $landing) {
            $leads = Lead::where('landing_page_id', $landing->id)
                ->whereNotNull('email')
                ->whereDoesntHave('stepEmails', fn ($q) => $q->where('step', 'webinar_recording'))
                ->get();

            foreach ($leads as $lead) {
                if ($mailer->deliver($lead, 'webinar_recording') === 'sent') {
                    $sent++;
                }
            }
        }

        $this->info("Webinar recording delivery: sent {$sent} email(s).");

        return self::SUCCESS;
    }
}
