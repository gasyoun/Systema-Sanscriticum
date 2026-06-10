<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Services\Leads\LeadMagnetDispatcher;
use Illuminate\Console\Command;

/**
 * Выдаёт лид-магниты лидам, у чьего лендинга открылось окно «за N минут до вебинара».
 * Лиды, запустившие бота раньше окна, ждут именно здесь; идемпотентность — за
 * SendLeadMagnet (magnet_delivered_at). Запуск каждые 5 минут (Kernel::schedule).
 */
final class DeliverDueLeadMagnets extends Command
{
    protected $signature = 'magnets:deliver-due';

    protected $description = 'Выдать лид-магниты лидам в окне «за N минут до вебинара»';

    public function handle(): int
    {
        $landings = LandingPage::whereNotNull('webinar_date')
            ->get()
            ->filter(fn (LandingPage $landing): bool => $landing->isMagnetWindowOpen());

        $queued = 0;

        foreach ($landings as $landing) {
            $leads = Lead::where('landing_page_id', $landing->id)
                ->whereNull('magnet_delivered_at')
                ->where(function ($q) {
                    $q->whereNotNull('telegram_chat_id')
                        ->orWhereNotNull('vk_user_id')
                        ->orWhereNotNull('max_user_id');
                })
                ->get();

            foreach ($leads as $lead) {
                $channel = $lead->telegram_chat_id ? 'telegram'
                    : ($lead->vk_user_id ? 'vk' : 'max');

                LeadMagnetDispatcher::deliverOrDefer($lead, $channel);
                $queued++;
            }
        }

        $this->info("Lead-magnet delivery: queued {$queued} lead(s).");

        return self::SUCCESS;
    }
}
