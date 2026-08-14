<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\Course;
use App\Models\StorefrontAnalyticsEvent;
use App\Support\FlagshipExperiments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Flag-gated storefront experiment logger (H2762).
 *
 * Guests are first-class: ActivityEvent cannot store them. GameEvent is
 * drills and is not used here. Failures never break the shop.
 */
final class StorefrontAnalytics
{
    public function record(
        string $event,
        string $experiment,
        Request $request,
        ?Course $course = null,
        ?string $variant = null,
        ?string $target = null,
        bool $dedupeDay = false,
    ): void {
        if (! in_array($event, StorefrontAnalyticsEvent::EVENTS, true)) {
            return;
        }

        if (! Schema::hasTable('storefront_events')) {
            return;
        }

        $visitorId = FlagshipExperiments::visitorId($request);
        $flagshipKey = FlagshipExperiments::flagshipKey();

        if ($dedupeDay && $this->alreadyToday($event, $experiment, $visitorId, $course?->id, $target)) {
            return;
        }

        try {
            StorefrontAnalyticsEvent::query()->create([
                'event_name' => $event,
                'experiment' => $experiment,
                'variant' => $variant,
                'flagship_key' => $flagshipKey,
                'course_id' => $course?->id,
                'target' => $target,
                'visitor_id' => $visitorId,
                'user_id' => $request->user()?->id,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('StorefrontAnalytics write failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{
     *     days: int,
     *     from: string,
     *     to: string,
     *     flagship: string,
     *     rows: list<array{experiment: string, event_name: string, variant: ?string, n: int}>
     * }
     */
    public function countWindow(int $days = 7): array
    {
        $days = max(1, $days);
        $from = now()->subDays($days)->startOfDay();
        $to = now();

        $rows = [];
        if (Schema::hasTable('storefront_events')) {
            $rows = StorefrontAnalyticsEvent::query()
                ->selectRaw('experiment, event_name, variant, count(*) as n')
                ->where('created_at', '>=', $from)
                ->where('created_at', '<=', $to)
                ->groupBy('experiment', 'event_name', 'variant')
                ->orderBy('experiment')
                ->orderBy('event_name')
                ->get()
                ->map(fn ($row) => [
                    'experiment' => (string) $row->experiment,
                    'event_name' => (string) $row->event_name,
                    'variant' => $row->variant !== null ? (string) $row->variant : null,
                    'n' => (int) $row->n,
                ])
                ->all();
        }

        return [
            'days' => $days,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'flagship' => FlagshipExperiments::flagshipKey(),
            'rows' => $rows,
        ];
    }

    private function alreadyToday(
        string $event,
        string $experiment,
        string $visitorId,
        ?int $courseId,
        ?string $target,
    ): bool {
        return StorefrontAnalyticsEvent::query()
            ->where('event_name', $event)
            ->where('experiment', $experiment)
            ->where('visitor_id', $visitorId)
            ->where('created_at', '>=', now()->startOfDay())
            ->when($courseId !== null, fn ($q) => $q->where('course_id', $courseId))
            ->when($target !== null, fn ($q) => $q->where('target', $target))
            ->exists();
    }
}
