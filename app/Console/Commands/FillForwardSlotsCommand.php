<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentCalendarSlot;
use App\Models\ContentCandidate;
use App\Services\Content\ForwardDraftGenerator;
use Illuminate\Console\Command;

/**
 * Fill empty `forward`-type ContentCalendarSlot rows for YYYY-MM with NEW
 * CuratorAi-drafted copy (H1567, content calendar W4). Skip-review default
 * (D12): forward is NEW copy, so a filled slot only ever goes empty -> draft
 * — never scheduled. Only a human monthly Keep in Filament schedules it.
 */
class FillForwardSlotsCommand extends Command
{
    protected $signature = 'content:fill-forward
                            {month : YYYY-MM}
                            {--limit= : Max slots to fill this run (cost cap; default config content.forward_draft_max_per_run)}
                            {--force-flag : Run even when CONTENT_CALENDAR_ENABLED is false (for tests/CI)}';

    protected $description = 'Fill empty forward calendar slots with CuratorAi-drafted NEW copy';

    public function handle(ForwardDraftGenerator $generator): int
    {
        if (! config('features.content_calendar') && ! $this->option('force-flag')) {
            $this->warn('content_calendar flag is OFF — pass --force-flag to fill anyway, or enable CONTENT_CALENDAR_ENABLED.');

            return self::SUCCESS;
        }

        $monthArg = (string) $this->argument('month');
        if (! preg_match('/^(\d{4})-(\d{2})$/', $monthArg, $m)) {
            $this->error('month must be YYYY-MM');

            return self::FAILURE;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        if ($month < 1 || $month > 12) {
            $this->error('invalid month');

            return self::FAILURE;
        }

        $limitOption = $this->option('limit');
        $limit = $limitOption !== null
            ? max(0, (int) $limitOption)
            : (int) config('content.forward_draft_max_per_run', ForwardDraftGenerator::DEFAULT_MAX_PER_RUN);

        $slots = ContentCalendarSlot::query()
            ->forMonth($year, $month)
            ->where('slot_type', ContentCalendarSlot::TYPE_FORWARD)
            ->where('status', ContentCalendarSlot::STATUS_EMPTY)
            ->orderBy('slot_date')
            ->limit($limit)
            ->get();

        if ($slots->isEmpty()) {
            $this->info("no empty forward slots for {$monthArg}");

            return self::SUCCESS;
        }

        $filled = 0;
        foreach ($slots as $i => $slot) {
            $kind = $generator->kindForIndex($i);
            $draft = $generator->draft($kind, seed: $slot->id);
            if ($draft === null) {
                continue;
            }

            $slot->forceFill([
                'status' => ContentCalendarSlot::STATUS_DRAFT,
                'title' => $draft['title'],
                'body' => $draft['body'],
                'meta' => array_merge($slot->meta ?? [], $draft['meta'], ['forward_kind' => $draft['kind']]),
            ])->save();

            ContentCandidate::updateOrCreate(
                ['calendar_slot_id' => $slot->id, 'type' => ContentCandidate::TYPE_VK_POST],
                [
                    'status' => ContentCandidate::STATUS_DRAFT,
                    'title' => $draft['title'],
                    'body' => $draft['body'],
                    'channel_target' => 'vk_wall',
                    'meta' => $draft['meta'],
                ],
            );

            $filled++;
        }

        $this->info(sprintf(
            'fill-forward %s: filled=%d remaining_empty=%d',
            $monthArg,
            $filled,
            $slots->count() - $filled,
        ));

        return self::SUCCESS;
    }
}
