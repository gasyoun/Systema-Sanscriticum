<?php

declare(strict_types=1);

namespace App\Services\Marathon;

use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Services\Messaging\Contracts\DeliveryChannel;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared Day-1 drip send for the diagnostic marathon.
 *
 * Used by:
 * - {@see \App\Jobs\ProcessTelegramMagnetUpdate} — immediate send on /start
 *   (ignores personal-day gate; user just opened the bot).
 * - {@see \App\Console\Commands\DeliverDueMarathonContent} — cron catch-up
 *   when personal day ≥ 1 (default gate).
 *
 * Idempotent via day1_completed_at.
 */
final class MarathonDay1Sender
{
    /**
     * @param  bool  $ignorePersonalDay  true = send even on calendar day 0 (after /start)
     * @return bool true if a message was sent
     */
    public static function sendIfPending(
        Lead $lead,
        DeliveryChannel $channel,
        bool $ignorePersonalDay = false,
    ): bool {
        if (! $lead->telegram_chat_id || ! $lead->magnet_token) {
            return false;
        }

        $enrollment = MarathonEnrollment::query()
            ->where('lead_id', $lead->id)
            ->whereNull('day1_completed_at')
            ->first();

        if (! $enrollment) {
            return false;
        }

        if (! $ignorePersonalDay && $enrollment->currentDay() < 1) {
            return false;
        }

        $link = route('marathon.day', ['day' => 1, 'token' => $lead->magnet_token]);
        $text = str_replace('{link}', $link, (string) $enrollment->content('day1_message'));

        $channel->sendMessage((string) $lead->telegram_chat_id, $text);
        $enrollment->update(['day1_completed_at' => now()]);

        Log::info('MarathonDay1Sender — Day 1 sent', [
            'enrollment_id' => $enrollment->id,
            'lead_id' => $lead->id,
            'immediate' => $ignorePersonalDay,
        ]);

        return true;
    }

    /**
     * Best-effort wrapper: never throws (so lead-magnet delivery can still run).
     */
    public static function trySendIfPending(
        Lead $lead,
        DeliveryChannel $channel,
        bool $ignorePersonalDay = false,
    ): bool {
        try {
            return self::sendIfPending($lead, $channel, $ignorePersonalDay);
        } catch (Throwable $e) {
            Log::error('MarathonDay1Sender failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
