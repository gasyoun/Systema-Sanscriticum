<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Jobs\PublishCalendarSlotJob;
use App\Models\ContentCalendarSlot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Content calendar auto-pilot (H1568; кросспостинг с 22-10-2026). Каждый
 * `scheduled` слот с наступившим publish_at проходит чек-лист §2.8 и уходит
 * в PublishCalendarSlotJob — тот публикует его во все выбранные TG-каналы и
 * VK-сообщества (config/content_calendar.php) и повторяет неудавшиеся
 * площадки на следующих тиках. До 22-10-2026 слот уходил одним n8n-вебхуком
 * в одну VK-стену; статуса по площадкам там быть не могло.
 */
class CalendarPublishService
{
    public function __construct(private readonly ContentProhibitionsChecklist $checklist = new ContentProhibitionsChecklist) {}

    /** @return array{dispatched: int, held: int} */
    public function publishDue(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now('Europe/Moscow');

        $due = ContentCalendarSlot::query()
            ->scheduled()
            ->where('publish_at', '<=', $now)
            ->orderBy('publish_at')
            ->get();

        $dispatched = 0;
        $held = 0;

        foreach ($due as $slot) {
            if (! $this->passesChecklist($slot)) {
                $held++;

                continue;
            }

            PublishCalendarSlotJob::dispatch($slot->id);
            $dispatched++;
        }

        return ['dispatched' => $dispatched, 'held' => $held];
    }

    private function passesChecklist(ContentCalendarSlot $slot): bool
    {
        // Already partly published (some destinations done): the text went
        // through the checklist on the first tick — never hold it half-way.
        if ($slot->publications()->exists()) {
            return true;
        }

        // H5020 pre-send checklist (§2.8 ratified 16-09-2026): a blocked slot
        // is held back as `draft` for a human edit — never posted, never
        // silently retried every hour, reason kept in meta.prohibition_hold.
        $verdict = $this->checklist->check((string) $slot->title."\n".PostFormatter::toPlain($slot->body));
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

        return true;
    }
}
