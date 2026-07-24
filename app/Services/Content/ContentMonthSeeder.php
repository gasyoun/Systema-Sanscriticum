<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCalendarSlot;
use App\Models\ContentCandidate;
use Illuminate\Support\Carbon;

/**
 * Seed a calendar month of empty/draft slots from vk-ors activity density (H1564).
 */
final class ContentMonthSeeder
{
    public function __construct(
        private readonly VkOrsImporter $importer = new VkOrsImporter,
    ) {}

    /**
     * @return array{created: int, skipped: int, target: int}
     */
    public function seed(int $year, int $month, ?string $dataPath = null): array
    {
        $dir = VkOrsImporter::resolvePath($dataPath);
        $activity = $this->importer->readCsv($dir, 'activity_by_month.csv');
        $historical = $this->importer->historicalPostsForMonth($activity, $month);

        // Plan default: max(20, historical/4) capped 40.
        $target = max(20, (int) round($historical / 4));
        $target = min(40, $target);

        $existing = ContentCalendarSlot::query()
            ->forMonth($year, $month)
            ->count();
        if ($existing >= $target) {
            return ['created' => 0, 'skipped' => $existing, 'target' => $target];
        }

        $need = $target - $existing;
        $start = Carbon::create($year, $month, 1, 0, 0, 0, 'Europe/Moscow');
        $daysInMonth = $start->daysInMonth;

        // Type mix: ~50% evergreen, ~30% systema-bridge placeholders, ~20% forward.
        $types = [];
        for ($i = 0; $i < $need; $i++) {
            $r = $i / max(1, $need);
            if ($r < 0.5) {
                $types[] = ContentCalendarSlot::TYPE_EVERGREEN;
            } elseif ($r < 0.8) {
                $types[] = ContentCalendarSlot::TYPE_CLIP_TEASE;
            } else {
                $types[] = ContentCalendarSlot::TYPE_FORWARD;
            }
        }

        $created = 0;
        for ($i = 0; $i < $need; $i++) {
            // Spread across month: day 1 .. daysInMonth cycling.
            $day = (int) floor($i * $daysInMonth / max(1, $need)) + 1;
            $day = min($daysInMonth, max(1, $day));
            $date = Carbon::create($year, $month, $day, 0, 0, 0, 'Europe/Moscow');

            $type = $types[$i];
            $status = $type === ContentCalendarSlot::TYPE_EVERGREEN
                ? ContentCalendarSlot::STATUS_DRAFT
                : ContentCalendarSlot::STATUS_EMPTY;

            $slot = ContentCalendarSlot::create([
                'channel' => ContentCalendarSlot::CHANNEL_VK_WALL,
                'slot_date' => $date->toDateString(),
                'slot_type' => $type,
                'status' => $status,
                'title' => match ($type) {
                    ContentCalendarSlot::TYPE_EVERGREEN => 'Evergreen (черновик)',
                    ContentCalendarSlot::TYPE_CLIP_TEASE => 'Клип / мост Systema',
                    ContentCalendarSlot::TYPE_FORWARD => 'Новый текст (forward)',
                    default => null,
                },
                'body' => null,
                'meta' => [
                    'seeded_by' => 'content:seed-month',
                    'historical_month_avg_posts' => $historical,
                    'vk_ors_path' => $dir,
                ],
            ]);

            // Optional empty candidate shell for evergreen-capable slots (W2 fills body).
            if ($type === ContentCalendarSlot::TYPE_EVERGREEN) {
                ContentCandidate::create([
                    'type' => ContentCandidate::TYPE_VK_POST,
                    'status' => ContentCandidate::STATUS_DRAFT,
                    'calendar_slot_id' => $slot->id,
                    'title' => $slot->title,
                    'body' => null,
                    'channel_target' => 'vk_wall',
                    'meta' => ['placeholder' => true],
                ]);
            }

            $created++;
        }

        return ['created' => $created, 'skipped' => $existing, 'target' => $target];
    }
}
