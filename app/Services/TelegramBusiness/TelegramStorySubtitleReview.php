<?php

declare(strict_types=1);

namespace App\Services\TelegramBusiness;

use App\Jobs\PublishTelegramBusinessStory;
use App\Models\TelegramBusinessStoryPublication;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Seven-day subtitle review, with a gap-filling original-video release. */
final class TelegramStorySubtitleReview
{
    public function holdIfCovered(TelegramBusinessStoryPublication $ledger, array $post, string $source): bool
    {
        if (! config('services.telegram_business.story_subtitles_enabled', true)
            || $ledger->subtitle_status !== null) {
            return false;
        }

        return Cache::lock('telegram-business-story-subtitle-coverage', 30)->block(10, function () use ($ledger, $post, $source): bool {
            if (! $this->hasCoverage($ledger->id)) {
                return false;
            }

            $directory = storage_path('app/telegram-business-story-subtitles');
            File::ensureDirectoryExists($directory);
            $bytes = filesize($source);
            $free = disk_free_space($directory);
            if ($bytes === false || $bytes > 1_000_000_000 || $free === false || $free < $bytes + 10_000_000_000) {
                Log::warning('Story subtitle staging skipped; publishing original without delay', [
                    'publication_id' => $ledger->id, 'source_bytes' => $bytes, 'free_bytes' => $free,
                ]);

                return false;
            }
            $path = $directory.'/'.$ledger->id.'.mp4';
            if (! File::copy($source, $path)) {
                throw new RuntimeException('Unable to stage Story source for off-server subtitles.');
            }
            // The signed HTTP reader may be a different local service user.
            // This is already-public editorial-channel media, outside public/.
            @chmod($path, 0644);
            $ledger->update([
                'source_post' => $post,
                'source_media_path' => $path,
                'subtitle_status' => 'pending',
                'subtitle_requested_at' => now(),
                'subtitle_deadline_at' => now()->addDays(7),
                'status' => 'subtitle_pending',
                'started_at' => null,
                'deferred_until' => null,
                'error' => null,
            ]);

            return true;
        });
    }

    public function hasCoverage(?int $exceptId = null): bool
    {
        $coverageSeconds = max(3600, (int) config('services.telegram_business.story_active_period', 86400) - 3600);

        return TelegramBusinessStoryPublication::query()
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where(function ($query) use ($coverageSeconds): void {
                $query->where('last_story_posted_at', '>=', now()->subSeconds($coverageSeconds))
                    ->orWhere(function ($inFlight): void {
                        $inFlight->whereIn('status', ['received', 'partial'])
                            ->where('started_at', '>=', now()->subMinutes(20));
                    })
                    ->orWhere(function ($queued): void {
                        $queued->where('status', 'subtitle_release')
                            ->where('updated_at', '>=', now()->subMinutes(30));
                    });
            })
            ->exists();
    }

    /** Release overdue originals and one oldest draft if today's Story coverage is empty. */
    public function releaseDue(): int
    {
        return Cache::lock('telegram-business-story-subtitle-coverage', 30)->block(10, function (): int {
            $pending = TelegramBusinessStoryPublication::query()
                ->where('status', 'subtitle_pending')
                ->orderBy('subtitle_requested_at')->get();
            $released = 0;
            $coverage = $this->hasCoverage();
            foreach ($pending as $ledger) {
                $overdue = $ledger->subtitle_deadline_at?->isPast() ?? false;
                if (! $overdue && $coverage) {
                    continue;
                }
                if (! is_array($ledger->source_post)) {
                    $ledger->update(['status' => 'failed', 'error' => 'Subtitle queue lost its source post.']);

                    continue;
                }
                $ledger->update(['status' => 'subtitle_release', 'error' => $overdue
                    ? 'Subtitle review deadline reached; publishing original video.'
                    : 'No active Story; publishing original video to fill the gap.']);
                PublishTelegramBusinessStory::dispatch($ledger->source_post);
                $released++;
                // An in-flight Story covers the empty day until its upload resolves.
                if (! $overdue) {
                    break;
                }
            }

            return $released;
        });
    }
}
