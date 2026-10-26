<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ContentCalendarPublication;
use App\Models\ContentCalendarSlot;
use App\Models\ContentCandidate;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\Content\CalendarBusinessStoryPublisher;
use App\Services\Content\CalendarPublishException;
use App\Services\Content\CalendarPublishService;
use App\Services\Content\CalendarTelegramPublisher;
use App\Services\Content\CalendarVkPublisher;
use App\Support\TelegramSendGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Кросспостинг одного слота календаря во все выбранные площадки (22-10-2026).
 *
 * На каждую площадку — строка content_calendar_publications: published не
 * уходит повторно никогда, неудача остаётся pending и повторяется на
 * следующем часовом тике content:publish-due, после max_attempts (или сразу
 * при permanent-отказе) — failed + уведомление админам. Перед каждой
 * отправкой — клейм TelegramSendGuard (точка отправки, CLAUDE.md), чтобы два
 * одновременных прогона не опубликовали пост дважды.
 *
 * Слот становится published, когда ни одной pending-площадки не осталось
 * (failed видны в таблице календаря и в уведомлении).
 */
class PublishCalendarSlotJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    private const IN_FLIGHT_TTL = 900;

    private const DONE_TTL = 2592000;

    public function __construct(public readonly int $slotId)
    {
        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-long');
        }
        $this->onQueue('messages');
    }

    public function uniqueId(): string
    {
        return (string) $this->slotId;
    }

    public function handle(
        CalendarTelegramPublisher $telegram,
        CalendarVkPublisher $vk,
        CalendarBusinessStoryPublisher $story,
        TelegramAdminNotifier $notifier,
        CalendarPublishService $service,
    ): void {
        $slot = ContentCalendarSlot::find($this->slotId);
        if ($slot === null || $slot->status !== ContentCalendarSlot::STATUS_SCHEDULED) {
            return;
        }

        // Слот из TG-канала диспатчится в обход content:publish-due — чек-лист
        // §2.8 здесь (для остальных no-op: тик уже отметил checklist_passed_at).
        if (! $service->passesChecklist($slot)) {
            $notifier->notifyAdmins("Календарь контента, слот #{$slot->id}: пост не разнесён по площадкам — {$slot->meta['prohibition_hold']}");

            return;
        }

        $targets = $slot->targetDestinations();
        if ($targets === []) {
            $slot->forceFill([
                'status' => ContentCalendarSlot::STATUS_DRAFT,
                'meta' => array_merge($slot->meta ?? [], [
                    'crosspost_hold' => 'не выбрано ни одной включённой площадки',
                ]),
            ])->save();
            Log::warning('PublishCalendarSlotJob: нет площадок — слот возвращён в draft', ['calendar_slot_id' => $slot->id]);

            return;
        }

        $destinations = ContentCalendarSlot::enabledDestinations();
        $maxAttempts = (int) config('content_calendar.max_attempts', 3);
        $report = [];

        foreach ($targets as $key) {
            $publication = ContentCalendarPublication::firstOrCreate([
                'content_calendar_slot_id' => $slot->id,
                'destination' => $key,
            ]);
            if ($publication->isDone()) {
                continue;
            }

            $claim = "content-calendar:{$slot->id}:{$key}";
            if (! TelegramSendGuard::claimKey($claim, self::IN_FLIGHT_TTL)) {
                continue; // этот же пост уже в полёте в параллельном прогоне
            }

            $destination = $destinations[$key];
            $publication->increment('attempts');

            try {
                $result = match ($destination['platform']) {
                    'vk' => $vk->publish($slot, $destination),
                    'vk_story' => $vk->publishStory($slot, $destination),
                    'tg_business_story' => $story->publish($slot, $destination),
                    default => $telegram->publish($slot, $destination),
                };
            } catch (Throwable $e) {
                $permanent = $e instanceof CalendarPublishException && $e->permanent;
                $failed = $permanent || $publication->attempts >= $maxAttempts;
                $publication->update([
                    'status' => $failed ? ContentCalendarPublication::STATUS_FAILED : ContentCalendarPublication::STATUS_PENDING,
                    'error' => mb_substr($e->getMessage(), 0, 1000),
                ]);
                Log::warning('PublishCalendarSlotJob: площадка не опубликована', [
                    'calendar_slot_id' => $slot->id,
                    'destination' => $key,
                    'attempt' => $publication->attempts,
                    'failed' => $failed,
                    'error' => $e->getMessage(),
                ]);
                if ($failed) {
                    $report[] = "❌ {$destination['label']}: {$e->getMessage()}";
                }

                continue;
            }

            TelegramSendGuard::extendKey($claim, self::DONE_TTL);
            $publication->update([
                'status' => ContentCalendarPublication::STATUS_PUBLISHED,
                'external_id' => $result['external_id'],
                'permalink' => $result['permalink'],
                'warning' => $result['warning'],
                'error' => null,
                'published_at' => now(),
            ]);
            if ($result['warning'] !== null) {
                $report[] = "⚠️ {$destination['label']}: {$result['warning']}";
            }
        }

        $this->finalize($slot, $targets);

        if ($report !== []) {
            $notifier->notifyAdmins("Календарь контента, слот #{$slot->id} ({$slot->slot_date?->format('d.m.Y')}):\n".implode("\n", $report));
        }
    }

    /** @param  list<string>  $targets */
    private function finalize(ContentCalendarSlot $slot, array $targets): void
    {
        $pending = $slot->publications()
            ->whereIn('destination', $targets)
            ->where('status', '!=', ContentCalendarPublication::STATUS_PUBLISHED)
            ->where('status', '!=', ContentCalendarPublication::STATUS_FAILED)
            ->count();
        $recorded = $slot->publications()->whereIn('destination', $targets)->count();
        if ($pending > 0 || $recorded < count($targets)) {
            return; // повторим на следующем тике
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
    }
}
