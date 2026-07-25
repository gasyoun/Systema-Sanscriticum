<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentCalendarSlot;
use App\Models\ContentCandidate;
use App\Services\Content\EvergreenScorer;
use App\Services\Content\VkOrsImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Fill empty `evergreen`-type ContentCalendarSlot rows for YYYY-MM with verbatim
 * top vk-ors posts (H1565, content calendar W2). Skip-review default (D12):
 * evergreen is not NEW copy, so a filled slot goes straight to `scheduled`
 * (publish_at = its existing slot_date, noon Europe/Moscow) rather than
 * waiting on a monthly human Keep.
 */
class FillEvergreenSlotsCommand extends Command
{
    protected $signature = 'content:fill-evergreen
                            {month : YYYY-MM}
                            {--path= : vk-ors CSV directory}
                            {--force-flag : Run even when CONTENT_CALENDAR_ENABLED is false (for tests/CI)}';

    protected $description = 'Fill draft evergreen calendar slots with verbatim top vk-ors posts';

    public function handle(VkOrsImporter $importer, EvergreenScorer $scorer): int
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

        $slots = ContentCalendarSlot::query()
            ->forMonth($year, $month)
            ->where('slot_type', ContentCalendarSlot::TYPE_EVERGREEN)
            ->where('status', ContentCalendarSlot::STATUS_DRAFT)
            ->orderBy('slot_date')
            ->get();

        if ($slots->isEmpty()) {
            $this->info("no draft evergreen slots for {$monthArg}");

            return self::SUCCESS;
        }

        try {
            $dir = VkOrsImporter::resolvePath($this->option('path') ?: null);
            $topPosts = $importer->readCsv($dir, 'top_posts_by_likes.csv');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $monthStart = Carbon::create($year, $month, 1, 0, 0, 0, 'Europe/Moscow');
        $windowStart = $monthStart->copy()->subMonths(6);
        $windowEnd = $monthStart->copy()->addMonths(6)->endOfMonth();

        $excludeSourceRefs = ContentCalendarSlot::query()
            ->where('source_kind', 'vk_ors_post')
            ->whereNotNull('source_ref')
            ->whereBetween('slot_date', [$windowStart->toDateString(), $windowEnd->toDateString()])
            ->pluck('source_ref')
            ->all();

        $scored = $scorer->score($topPosts, now(), $excludeSourceRefs);

        $filled = 0;
        foreach ($slots as $slot) {
            $post = array_shift($scored);
            if ($post === null) {
                break;
            }

            $slot->forceFill([
                'title' => 'Evergreen: '.mb_substr($post['excerpt'], 0, 60),
                'body' => $post['excerpt'],
                'source_kind' => 'vk_ors_post',
                'source_ref' => $post['id'],
                'meta' => array_merge($slot->meta ?? [], [
                    'evergreen_source_url' => $post['url'],
                    'evergreen_source_date' => $post['date'],
                    'evergreen_likes' => $post['likes'],
                    'evergreen_topic' => $post['topic'],
                ]),
            ]);
            $slot->markKept();

            $candidate = $slot->candidates()->where('type', ContentCandidate::TYPE_VK_POST)->first();
            if ($candidate !== null) {
                $candidate->forceFill([
                    'status' => ContentCandidate::STATUS_SCHEDULED,
                    'title' => $slot->title,
                    'body' => $post['excerpt'],
                    'channel_target' => 'vk_wall',
                    'meta' => [
                        'source_post_id' => $post['id'],
                        'permalink' => $post['url'],
                        'likes' => $post['likes'],
                        'topic' => $post['topic'],
                    ],
                ])->save();
            }

            $filled++;
        }

        $this->info(sprintf(
            'fill-evergreen %s: filled=%d remaining_draft=%d candidates_available=%d',
            $monthArg,
            $filled,
            $slots->count() - $filled,
            count($scored),
        ));

        return self::SUCCESS;
    }
}
