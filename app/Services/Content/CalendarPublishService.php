<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCalendarSlot;
use App\Models\ContentCandidate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * VK/ORS content calendar Wave 5 auto-pilot (H1568). Posts every `scheduled`
 * slot whose publish_at is due to the n8n VK wall.post webhook — same
 * webhook-forward shape as PublishSocialPostJob/PostMonthlySchedule; Laravel
 * never calls the VK API directly (E4). A slot that the webhook rejects
 * STAYS `scheduled` (retried on the next hourly tick) rather than being
 * silently dropped or marked published.
 */
class CalendarPublishService
{
    /** @return array{published: int, failed: int, skipped_no_webhook: bool} */
    public function publishDue(?Carbon $now = null): array
    {
        $webhook = config('services.n8n.calendar_post_webhook');
        if (empty($webhook)) {
            Log::warning('CalendarPublishService: N8N_CALENDAR_POST_WEBHOOK не задан — пропуск.');

            return ['published' => 0, 'failed' => 0, 'skipped_no_webhook' => true];
        }

        $now = $now ?? Carbon::now('Europe/Moscow');

        $due = ContentCalendarSlot::query()
            ->scheduled()
            ->where('publish_at', '<=', $now)
            ->orderBy('publish_at')
            ->get();

        $published = 0;
        $failed = 0;

        foreach ($due as $slot) {
            if ($this->publishOne($slot, $webhook)) {
                $published++;
            } else {
                $failed++;
            }
        }

        return ['published' => $published, 'failed' => $failed, 'skipped_no_webhook' => false];
    }

    private function publishOne(ContentCalendarSlot $slot, string $webhook): bool
    {
        $response = Http::withHeaders([
            'X-Webhook-Secret' => (string) config('services.n8n.calendar_post_secret'),
        ])->timeout(15)->post($webhook, [
            'action' => 'vk_calendar_post',
            'calendar_slot_id' => $slot->id,
            'slot_type' => $slot->slot_type,
            'text_vk' => (string) $slot->body,
            'source_kind' => $slot->source_kind,
            'source_ref' => $slot->source_ref,
            'publish_at' => $slot->publish_at?->toIso8601String(),
        ]);

        if (! $response->successful()) {
            Log::error('CalendarPublishService: n8n вернул '.$response->status(), [
                'calendar_slot_id' => $slot->id,
                'body' => $response->body(),
            ]);

            return false;
        }

        $slot->forceFill(['status' => ContentCalendarSlot::STATUS_PUBLISHED])->save();

        $slot->candidates()
            ->whereIn('status', [
                ContentCandidate::STATUS_DRAFT,
                ContentCandidate::STATUS_ACCEPTED,
                ContentCandidate::STATUS_SCHEDULED,
            ])
            ->get()
            ->each(fn (ContentCandidate $candidate) => $candidate->update([
                'status' => ContentCandidate::STATUS_PUBLISHED,
                'published_at' => now(),
            ]));

        return true;
    }
}
