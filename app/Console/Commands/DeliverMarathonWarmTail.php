<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarathonEnrollment;
use App\Services\Messaging\DeliveryChannelManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * H440 Phase 6 — 13-day warm-tail auto-series for unpaid marathon
 * registrants (paid_at null), Days 4–16 off the personal day0_started_at
 * clock (MarathonEnrollment::warmTailDay()). Evergreen, no urgency framing
 * — matches the anti-urgency design (Uprava/custdev/MARATHON_DIAGNOSTIC_2026.md).
 * Idempotency is a single monotonic counter (warm_tail_last_day_sent), not
 * 13 boolean columns like the Day 1/2/3 drip — the warm-tail is a strictly
 * ordered progression with no branching. A registrant who pays mid-series
 * (paid_at set) drops out of the `whereNull('paid_at')` scope on the next
 * run and receives no further warm-tail messages.
 */
final class DeliverMarathonWarmTail extends Command
{
    protected $signature = 'marathon:deliver-warm-tail';

    protected $description = 'Доставить тёплый хвост (Дни 4-16) неоплатившим энролам марафона';

    public function handle(DeliveryChannelManager $channels): int
    {
        $channel = $channels->get('telegram');
        $messages = (array) config('marathon.warm_tail_messages', []);

        $sent = 0;

        $enrollments = MarathonEnrollment::with('lead')
            ->whereNull('paid_at')
            ->get();

        foreach ($enrollments as $enrollment) {
            $lead = $enrollment->lead;
            if (! $lead || ! $lead->telegram_chat_id) {
                continue;
            }

            $day = $enrollment->warmTailDay();
            if ($day === null) {
                continue;
            }

            if ($enrollment->warm_tail_last_day_sent !== null && $enrollment->warm_tail_last_day_sent >= $day) {
                continue;
            }

            $template = (string) ($messages[$day] ?? '');
            if ($template === '') {
                continue;
            }

            $text = str_replace(
                ['{host}', '{coupon}'],
                [(string) config('marathon.host_name'), (string) config('marathon.coupon_amount')],
                $template
            );

            $channel->sendMessage((string) $lead->telegram_chat_id, $text);
            $enrollment->update(['warm_tail_last_day_sent' => $day]);
            $sent++;
            Log::info("marathon:deliver-warm-tail — Day {$day} sent, enrollment #{$enrollment->id}");
        }

        $this->info("Marathon warm-tail delivery: sent {$sent} message(s).");

        return self::SUCCESS;
    }
}
