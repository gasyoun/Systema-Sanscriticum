<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PublishTelegramBusinessStory;
use App\Models\TelegramBusinessStoryPublication;
use App\Services\TelegramBusiness\TelegramStorySrt;
use App\Services\TelegramBusiness\TelegramStorySubtitleReview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;
use Throwable;

/** CLI bridge for Ivan/Air ASR; transcript bytes arrive over authenticated SSH stdin. */
final class TelegramBusinessStorySubtitles extends Command
{
    protected $signature = 'telegram-business:story-subtitles
        {action : queue, import, skip, or release}
        {id? : Publication id for import or skip}
        {--worker= : ivan or air label for import}
        {--limit=3 : Maximum queued sources to list}';

    protected $description = 'List subtitle drafts, import off-server SRT, or release due Stories';

    public function handle(TelegramStorySubtitleReview $review): int
    {
        $action = (string) $this->argument('action');
        if ($action === 'queue') {
            $limit = min(20, max(1, (int) $this->option('limit')));
            $rows = TelegramBusinessStoryPublication::query()
                ->where('status', 'subtitle_pending')
                ->where('subtitle_status', 'pending')
                ->orderBy('subtitle_requested_at')->limit($limit)->get()
                ->filter(fn (TelegramBusinessStoryPublication $item): bool => is_string($item->source_media_path) && is_file($item->source_media_path))
                ->map(fn (TelegramBusinessStoryPublication $item): array => [
                    'id' => $item->id,
                    'source_path' => $item->source_media_path,
                    'source_url' => rtrim((string) config('app.url'), '/').URL::temporarySignedRoute(
                        'telegram-story-subtitle-media', now()->addMinutes(30),
                        ['publication' => $item->id], absolute: false,
                    ),
                    'deadline' => $item->subtitle_deadline_at?->toIso8601String(),
                ])->values()->all();
            $this->line(json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        if ($action === 'release') {
            $this->line('Released '.$review->releaseDue().' Story video(s).');

            return self::SUCCESS;
        }
        if (! in_array($action, ['import', 'skip'], true) || ! ctype_digit((string) $this->argument('id'))) {
            $this->error('Use queue, release, import <id>, or skip <id>.');

            return self::FAILURE;
        }

        $ledger = TelegramBusinessStoryPublication::find((int) $this->argument('id'));
        if ($ledger === null || $ledger->status !== 'subtitle_pending' || $ledger->subtitle_status !== 'pending') {
            $this->error('Story subtitle request is no longer pending.');

            return self::FAILURE;
        }
        if ($action === 'skip') {
            $ledger->update(['subtitle_status' => 'unavailable', 'status' => 'subtitle_release',
                'error' => 'No speech detected; publishing original video.']);
            if (is_array($ledger->source_post)) {
                PublishTelegramBusinessStory::dispatch($ledger->source_post);
            }
            $this->info('Original Story queued without subtitles.');

            return self::SUCCESS;
        }
        $worker = (string) $this->option('worker');
        if (! in_array($worker, ['ivan', 'air'], true)) {
            $this->error('Worker must be ivan or air.');

            return self::FAILURE;
        }
        $srt = stream_get_contents(STDIN, 200_001);
        if (! is_string($srt)) {
            $this->error('No subtitle draft received.');

            return self::FAILURE;
        }
        try {
            TelegramStorySrt::cues($srt);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $ledger->update(['subtitle_draft' => $srt, 'subtitle_status' => 'ready', 'subtitle_worker' => $worker]);
        $this->info('Subtitle draft ready for Story #'.$ledger->id.'.');

        return self::SUCCESS;
    }
}
