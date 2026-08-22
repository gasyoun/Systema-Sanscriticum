<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarathonEnrollment;
use App\Services\Messaging\DeliveryChannelManager;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 *
 * H2701 — a Telegram HTTP timeout after Bot API has already accepted
 * sendMessage must still advance the counter. Otherwise the next 15-minute
 * cron resends the same day (observed: day 12 twice). A hard API error
 * (blocked bot, 4xx/5xx body) is not marked, so a later run can retry.
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

            // H3330 — sequential waves: the wave is fixed by the enrolment
            // moment (not by send time), so a registrant's series never mixes
            // offers mid-flight even when the cutoff flips between sends.
            $wave = $enrollment->warmTailWave();
            $waveMessages = (array) config('marathon.warm_tail_messages_wave2', []);
            $template = (string) (($wave === MarathonEnrollment::WAVE_MEMBERSHIP && $waveMessages !== []
                ? $waveMessages
                : $messages)[$day] ?? '');
            if ($template === '') {
                continue;
            }

            $text = str_replace(
                [
                    '{host}',
                    '{coupon}',
                    '{testimonial}',
                    '{basic_price}',
                    '{club_price}',
                    '{klub_url}',
                ],
                [
                    (string) config('marathon.host_name'),
                    (string) config('marathon.coupon_amount'),
                    $this->testimonialText(),
                    (string) config('marathon.membership_basic_month_price', 1000),
                    (string) config('marathon.membership_club_month_price', 2000),
                    (string) config('marathon.membership_klub_url', 'https://samskrte.ru/klub'),
                ],
                $template
            );

            try {
                $channel->sendMessage((string) $lead->telegram_chat_id, $text);
            } catch (ConnectionException $e) {
                // Bot API often delivers before the HTTP client sees a body.
                // Marking here is at-most-once: one skipped day beats a duplicate.
                Log::warning('marathon:deliver-warm-tail — Telegram timeout, marking day sent to avoid a duplicate', [
                    'enrollment_id' => $enrollment->id,
                    'day' => $day,
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable $e) {
                Log::error('marathon:deliver-warm-tail — send failed, day not marked', [
                    'enrollment_id' => $enrollment->id,
                    'day' => $day,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $enrollment->update(['warm_tail_last_day_sent' => $day]);
            $sent++;
            Log::info("marathon:deliver-warm-tail — Day {$day} sent, enrollment #{$enrollment->id}", [
                'wave' => $wave,
            ]);
        }

        $this->info("Marathon warm-tail delivery: sent {$sent} message(s).");

        return self::SUCCESS;
    }

    /**
     * Real quote when MG has supplied one (config('marathon.testimonial'),
     * env MARATHON_TESTIMONIAL) — otherwise a safe framing that never
     * fabricates a first-person quote (MARATHON_DIAGNOSTIC_2026.md §4:
     * one pointed testimonial, never invented).
     */
    private function testimonialText(): string
    {
        $real = trim((string) config('marathon.testimonial'));
        if ($real !== '') {
            return 'Отзыв одного из практикующих сейчас 💬'."\n\n".'«'.$real.'»';
        }

        return 'Не собираем отзывы для галочки 💬'."\n\n"
            .'Когда будет что показать от практикующего — поделимся здесь. '
            .'А пока просто расскажем сами, чему учим и как.';
    }
}
