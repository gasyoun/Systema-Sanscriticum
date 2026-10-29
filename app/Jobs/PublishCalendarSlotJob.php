<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ContentCalendarPublication;
use App\Models\ContentCalendarSlot;
use App\Models\ContentCandidate;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\Content\CalendarPublicationRunner;
use App\Services\Content\CalendarPublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
        CalendarPublicationRunner $runner,
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
        $report = [];

        foreach ($targets as $key) {
            $line = $runner->run($slot, $key, $destinations[$key])['line'];
            if ($line !== null) {
                $report[] = $line;
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
