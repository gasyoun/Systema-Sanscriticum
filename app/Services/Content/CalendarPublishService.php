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

    /** @return array{dispatched: int, held: int, skipped_no_destinations: bool} */
    public function publishDue(?Carbon $now = null): array
    {
        // Площадки не настроены — тик ничего не трогает (слоты не уходят в
        // draft): прод включил автопилот раньше, чем появились CONTENT_CAL_*.
        if (ContentCalendarSlot::enabledDestinations() === []) {
            Log::warning('CalendarPublishService: площадки CONTENT_CAL_* не заданы — пропуск.');

            return ['dispatched' => 0, 'held' => 0, 'skipped_no_destinations' => true];
        }

        $now = $now ?? Carbon::now('Europe/Moscow');

        $due = ContentCalendarSlot::query()
            ->scheduled()
            ->where('publish_at', '<=', $now)
            ->orderBy('publish_at')
            ->get();

        $dispatched = 0;
        $held = 0;
        $staleBefore = $now->copy()->subHours((int) config('content_calendar.max_lateness_hours', 24));

        foreach ($due as $slot) {
            if ($slot->publications()->doesntExist() && $slot->publish_at->lt($staleBefore)) {
                // Протухший слот (автопилот стоял, площадок не было) не
                // выстреливает пачкой старых постов — ждёт новую дату.
                $slot->forceFill([
                    'status' => ContentCalendarSlot::STATUS_DRAFT,
                    'meta' => array_merge($slot->meta ?? [], [
                        'stale_hold' => 'publish_at '.$slot->publish_at->format('d.m.Y H:i').' прошёл больше '
                            .config('content_calendar.max_lateness_hours', 24).' ч назад — поставьте новую дату и Keep',
                    ]),
                ])->save();
                $held++;

                continue;
            }

            if (! $this->passesChecklist($slot)) {
                $held++;

                continue;
            }

            PublishCalendarSlotJob::dispatch($slot->id);
            $dispatched++;
        }

        return ['dispatched' => $dispatched, 'held' => $held, 'skipped_no_destinations' => false];
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
