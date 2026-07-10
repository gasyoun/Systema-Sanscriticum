<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarathonEnrollment;
use App\Services\Messaging\DeliveryChannelManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * H440/H464 — доставляет Day 1/Day 2 контент диагностического марафона по
 * личным дням (MarathonEnrollment::currentDay()), НЕ по общему календарю.
 * Идемпотентность — day{N}_completed_at (в этом слайсе "delivered" ==
 * "completed": контент самодостаточный, распознавание без взаимодействия,
 * отдельного события "прочитано/выполнено" не отслеживаем — см. H464 §
 * "Scope decision"). Энрол без telegram_chat_id (бот не запущен) пропускается
 * молча — догонит на следующем прогоне, когда лид запустит бота.
 */
final class DeliverDueMarathonContent extends Command
{
    protected $signature = 'marathon:deliver-due';

    protected $description = 'Доставить Day 1/Day 2 контент марафона энролам, чей личный день наступил';

    public function handle(DeliveryChannelManager $channels): int
    {
        $channel = $channels->get('telegram');

        $sent = 0;

        $enrollments = MarathonEnrollment::with('lead')
            ->where(function ($q) {
                $q->whereNull('day1_completed_at')
                    ->orWhereNull('day2_completed_at');
            })
            ->get();

        foreach ($enrollments as $enrollment) {
            $lead = $enrollment->lead;
            if (! $lead || ! $lead->telegram_chat_id) {
                continue;
            }

            $day = $enrollment->currentDay();

            if ($day >= 1 && $enrollment->day1_completed_at === null) {
                $channel->sendMessage((string) $lead->telegram_chat_id, config('marathon.day1_message'));
                $enrollment->update(['day1_completed_at' => now()]);
                $sent++;
                Log::info("marathon:deliver-due — Day 1 sent, enrollment #{$enrollment->id}");
            }

            if ($day >= 2 && $enrollment->day2_completed_at === null) {
                $channel->sendMessage((string) $lead->telegram_chat_id, config('marathon.day2_message'));
                $enrollment->update(['day2_completed_at' => now()]);
                $sent++;
                Log::info("marathon:deliver-due — Day 2 sent, enrollment #{$enrollment->id}");
            }
        }

        $this->info("Marathon content delivery: sent {$sent} message(s).");

        return self::SUCCESS;
    }
}
