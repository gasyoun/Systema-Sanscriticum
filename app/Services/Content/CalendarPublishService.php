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

    public function __construct(private readonly ContentProhibitionsChecklist $checklist = new ContentProhibitionsChecklist) {}

    private function publishOne(ContentCalendarSlot $slot, string $webhook): bool
    {
        // H5020 pre-send checklist (§2.8 ratified 16-09-2026): a blocked slot
        // is held back as `draft` for a human edit — never posted, never
        // silently retried every hour, reason kept in meta.prohibition_hold.
        $verdict = $this->checklist->check((string) $slot->title."\n".(string) $slot->body);
        if (! $verdict['ok']) {
            $slot->forceFill([
                'status' => ContentCalendarSlot::STATUS_DRAFT,
                'meta' => array_merge($slot->meta ?? [], [
                    'prohibition_hold' => $this->checklist->journalLine($verdict),
                    'prohibition_hold_at' => now()->toDateTimeString(),
                ]),
            ])->save();
            Log::warning('CalendarPublishService: слот удержан чек-листом §2.8', [
                'calendar_slot_id' => $slot->id,
                'hold' => $this->checklist->journalLine($verdict),
            ]);

            return false;
        }
        if ($verdict['warns'] !== []) {
            $slot->forceFill(['meta' => array_merge($slot->meta ?? [], [
                'prohibition_warn' => array_map(static fn (array $w): string => $w['rule'].' («'.$w['match'].'»)', $verdict['warns']),
            ])])->save();
        }

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

        $slot->forceFill([
            'status' => ContentCalendarSlot::STATUS_PUBLISHED,
            // Digest source (H5020): when the autopilot actually posted it.
            'meta' => array_merge($slot->meta ?? [], ['autopilot_published_at' => now()->toDateTimeString()]),
        ])->save();

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
