<?php

declare(strict_types=1);

namespace App\Services\TelegramBusiness;

use App\Jobs\PublishTelegramBusinessStory;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramBusinessStoryPublication;
use App\Services\Telegram\MadelineClientFactory;
use App\Services\Telegram\MadelineSessionContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/** Publishes an approved channel video as consecutive Business-account Stories. */
final class TelegramBusinessStoryPublisher
{
    private const STORY_BYTES_MAX = 30_000_000;

    private const STORY_SECONDS_MAX = 60;

    public function publishFromChannelPost(array $post): void
    {
        $chat = is_array($post['chat'] ?? null) ? $post['chat'] : [];
        $video = is_array($post['video'] ?? null) ? $post['video'] : null;
        $chatId = (string) ($chat['id'] ?? '');
        $messageId = (int) ($post['message_id'] ?? 0);

        if ($video === null || $chatId === '' || $messageId < 1 || ! $this->isSource($chat)) {
            return;
        }

        $ledger = TelegramBusinessStoryPublication::firstOrCreate(
            ['source_chat_id' => $chatId, 'source_message_id' => $messageId],
            ['telegram_file_unique_id' => (string) ($video['file_unique_id'] ?? ''), 'status' => 'received'],
        );
        if (in_array($ledger->status, ['published', 'duplicate', 'review', 'uncertain'], true)) {
            return;
        }
        if ($ledger->status === 'deferred' && $ledger->deferred_until?->isFuture()) {
            return;
        }
        if (! $this->reserveDailySlot($ledger, $post)) {
            return;
        }

        $tmp = null;
        try {
            $tmp = $this->download($chat, $messageId, (string) ($video['file_id'] ?? ''));
            $hash = hash_file('sha256', $tmp);
            if ($hash === false) {
                throw new RuntimeException('Unable to hash Story source video.');
            }
            $prior = TelegramBusinessStoryPublication::query()
                ->where('media_sha256', $hash)->where('id', '!=', $ledger->id)->first();
            if ($prior !== null) {
                // media_sha256 is unique: keep it on the first publisher and
                // point this later source message at that authoritative row.
                $ledger->update(['duplicate_of_id' => $prior->id, 'status' => 'duplicate', 'started_at' => null]);

                return;
            }

            $fingerprinter = app(TelegramStoryVideoFingerprint::class);
            $fingerprint = null;
            try {
                $fingerprint = $fingerprinter->fromFile($tmp, $this->sourceDuration($tmp));
            } catch (Throwable $e) {
                // A review signal must never block an otherwise valid editorial video.
                Log::warning('Telegram Story near-match fingerprint unavailable', [
                    'publication_id' => $ledger->id, 'error' => $e->getMessage(),
                ]);
            }
            if ($fingerprint !== null && $ledger->near_match_approved_at === null
                && $this->holdNearMatchForReview($ledger, $fingerprint, $hash, $post, $fingerprinter)) {
                return;
            }

            $ledger->update([
                'media_sha256' => $hash,
                'video_fingerprint' => $fingerprint,
                'source_post' => self::reviewSourcePost($post),
                'cta_url' => $ledger->cta_url ?? TelegramStoryLink::fromPost($post),
            ]);
            $hasMoreParts = $this->publishParts($ledger, $tmp, 1);
            if ($hasMoreParts) {
                PublishTelegramBusinessStory::dispatch($post)->delay(now()->addSeconds(5));
            }
        } catch (Throwable $e) {
            $ledger->update([
                'status' => $e instanceof StoryUploadOutcomeUnknown
                    ? 'uncertain'
                    : (count($ledger->story_ids ?? []) > 0 || $ledger->story_id ? 'partial' : 'failed'),
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            Log::warning('Telegram Business Story publish failed', ['publication_id' => $ledger->id, 'error' => $e->getMessage()]);
            if (! $e instanceof StoryUploadOutcomeUnknown) {
                throw $e;
            }
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
    }

    /** @param array{duration: float, frames: list<string>} $fingerprint */
    private function holdNearMatchForReview(
        TelegramBusinessStoryPublication $ledger,
        array $fingerprint,
        string $hash,
        array $post,
        TelegramStoryVideoFingerprint $fingerprinter,
    ): bool {
        $candidate = TelegramBusinessStoryPublication::query()
            ->whereNotNull('video_fingerprint')
            ->whereIn('status', ['published', 'partial'])
            ->where('id', '!=', $ledger->id)
            ->latest('id')->limit(1000)->get()
            ->first(fn (TelegramBusinessStoryPublication $item): bool => $fingerprinter->isNear($fingerprint, $item->video_fingerprint ?? []));
        if ($candidate === null) {
            return false;
        }

        $ledger->update([
            'media_sha256' => $hash,
            'video_fingerprint' => $fingerprint,
            'duplicate_of_id' => $candidate->id,
            'source_post' => self::reviewSourcePost($post),
            'started_at' => null,
            'status' => 'review',
            'error' => 'Possible near-duplicate: compare source posts before approving or suppressing.',
        ]);
        Log::warning('Telegram Business Story near-match requires review', [
            'publication_id' => $ledger->id, 'candidate_id' => $candidate->id,
        ]);

        return true;
    }

    /** Retain only the public channel message fields needed for a reviewed retry. */
    private static function reviewSourcePost(array $post): array
    {
        return [
            'chat' => [
                'id' => $post['chat']['id'] ?? null,
                'username' => $post['chat']['username'] ?? null,
            ],
            'message_id' => $post['message_id'] ?? null,
            'video' => [
                'file_id' => $post['video']['file_id'] ?? null,
                'file_unique_id' => $post['video']['file_unique_id'] ?? null,
            ],
            'caption' => $post['caption'] ?? null,
        ];
    }

    /** A source video occupies one daily slot, irrespective of its part count. */
    private function reserveDailySlot(TelegramBusinessStoryPublication $ledger, array $post): bool
    {
        if ($ledger->started_at !== null) {
            return true;
        }
        $cap = max(0, (int) config('services.telegram_business.story_daily_video_cap', 5));
        if ($cap === 0) {
            $ledger->update(['started_at' => now(), 'deferred_until' => null, 'status' => 'received']);

            return true;
        }

        return Cache::lock('telegram-business-story-daily-cap', 30)->block(10, function () use ($ledger, $post, $cap): bool {
            $ledger->refresh();
            if ($ledger->started_at !== null) {
                return true;
            }
            $today = now()->startOfDay();
            $started = TelegramBusinessStoryPublication::query()
                ->where('started_at', '>=', $today)
                ->where('started_at', '<', $today->copy()->addDay())
                ->where('status', '!=', 'duplicate')
                ->count();
            if ($started >= $cap) {
                $next = $today->copy()->addDay()->addMinutes(5);
                PublishTelegramBusinessStory::dispatch($post)->delay($next);
                $ledger->update(['status' => 'deferred', 'deferred_until' => $next]);

                return false;
            }
            $ledger->update(['started_at' => now(), 'deferred_until' => null, 'status' => 'received']);

            return true;
        });
    }

    /** Keep each successful upload before attempting the next; queued runs yield after one part. */
    private function publishParts(TelegramBusinessStoryPublication $ledger, string $source, int $maxParts = PHP_INT_MAX): bool
    {
        $duration = $this->sourceDuration($source);
        $segments = TelegramStorySegments::plan($duration);
        $parts = count($segments);
        $truncated = TelegramStorySegments::isTruncated($duration);
        $ids = $ledger->story_ids ?? ($ledger->story_id ? [$ledger->story_id] : []);
        $ledger->update(['part_count' => $parts, 'story_ids' => $ids]);
        $connection = $this->storyConnection();

        $stopAfter = min($parts, count($ids) + $maxParts);
        for ($part = count($ids); $part < $stopAfter; $part++) {
            $offset = $segments[$part]['offset'];
            $length = $segments[$part]['duration'];
            $video = $this->normalise($source, $offset, $length);
            try {
                $id = $this->postStory($connection->business_connection_id, $video, $length, $part + 1, $parts, $ledger->cta_url);
                $ids[] = $id;
                $ledger->update([
                    'story_ids' => $ids,
                    'story_id' => $ids[0],
                    'status' => count($ids) === $parts ? 'published' : 'partial',
                    'error' => count($ids) === $parts && $truncated
                        ? 'Source video exceeded 600 seconds; only the first 10 Story parts were published.'
                        : null,
                ]);
            } finally {
                @unlink($video);
            }
        }
        if ($truncated && count($ids) === $parts) {
            Log::warning('Telegram Business Story source truncated to 10 parts', [
                'publication_id' => $ledger->id,
                'source_duration_seconds' => $duration,
                'published_story_ids' => $ids,
            ]);
        }

        return count($ids) < $parts;
    }

    private function sourceDuration(string $source): float
    {
        $ffmpeg = (string) config('services.telegram_business.story_ffmpeg_binary', 'ffmpeg');
        $ffprobe = str_ends_with($ffmpeg, 'ffmpeg') ? substr($ffmpeg, 0, -6).'ffprobe' : 'ffprobe';
        $result = Process::timeout(30)->run([
            $ffprobe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $source,
        ]);
        $duration = (float) trim($result->output());
        if (! $result->successful() || ! is_finite($duration) || $duration <= 0) {
            throw new RuntimeException('Could not determine Story source video duration.');
        }

        return $duration;
    }

    /** @param array<string, mixed> $chat */
    private function isSource(array $chat): bool
    {
        $sources = config('services.telegram_business.story_sources', []);
        $candidates = [(string) ($chat['id'] ?? '')];
        if (($username = trim((string) ($chat['username'] ?? ''))) !== '') {
            $candidates[] = '@'.ltrim($username, '@');
        }

        return array_intersect($candidates, $sources) !== [];
    }

    /** @param array<string, mixed> $chat */
    private function download(array $chat, int $messageId, string $fileId): string
    {
        if ($fileId === '') {
            throw new RuntimeException('Source video has no Telegram file_id.');
        }
        $token = $this->token();
        $meta = Http::timeout(20)->get("https://api.telegram.org/bot{$token}/getFile", ['file_id' => $fileId]);
        $path = $meta->json('result.file_path');
        if (! $meta->successful() || ! is_string($path) || $path === '') {
            // The public Bot API intentionally caps downloads.  The existing
            // server-side MadelineProto session can read public source channels
            // directly, so use it only for this documented large-file case.
            if (str_contains(mb_strtolower($meta->body()), 'file is too big')) {
                return $this->downloadLargeSourceWithMadeline($chat, $messageId);
            }

            throw new RuntimeException('Telegram getFile failed: '.mb_substr($meta->body(), 0, 300));
        }
        $target = tempnam(sys_get_temp_dir(), 'tg-story-');
        if ($target === false) {
            throw new RuntimeException('Unable to allocate temporary Story media file.');
        }
        $download = Http::timeout(120)->sink($target)->get("https://api.telegram.org/file/bot{$token}/{$path}");
        if (! $download->successful()) {
            @unlink($target);
            throw new RuntimeException('Telegram media download failed: HTTP '.$download->status());
        }

        return $target;
    }

    /** @param array<string, mixed> $chat */
    private function downloadLargeSourceWithMadeline(array $chat, int $messageId): string
    {
        $factory = app(MadelineClientFactory::class);
        if (! $factory->isConfigured()) {
            throw new RuntimeException('Source video exceeds the Bot API download limit and the server Telegram media session is unavailable.');
        }

        $peer = trim((string) ($chat['username'] ?? ''));
        $peer = $peer !== '' ? '@'.ltrim($peer, '@') : (string) ($chat['id'] ?? '');
        if ($peer === '' || $messageId < 1) {
            throw new RuntimeException('Unable to identify the large-video source message.');
        }

        $lock = Cache::lock(MadelineSessionContext::lockName(), 900);
        try {
            $lock->block(10);
            $client = $factory->open();
            $history = $client->messages->getHistory([
                'peer' => $peer,
                'limit' => 1,
                'offset_id' => $messageId + 1,
            ]);
            $message = collect($history['messages'] ?? [])->first(
                fn (mixed $item): bool => is_array($item) && (int) ($item['id'] ?? 0) === $messageId,
            );
            if (! is_array($message) || empty($message['media'])) {
                throw new RuntimeException('Large-video source message is unavailable to the server Telegram session.');
            }

            $directory = storage_path('app/telegram-business-story-source');
            File::ensureDirectoryExists($directory);
            $path = $client->downloadToDir($message['media'], $directory);
            if (! is_string($path) || ! is_file($path)) {
                throw new RuntimeException('Large-video source download did not produce a local file.');
            }

            return $path;
        } catch (LockTimeoutException) {
            throw new RuntimeException('Server Telegram media session is busy; the Story source will be retried.');
        } finally {
            $lock->release();
        }
    }

    private function normalise(string $input, int $offset, float $length): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'tg-story-out-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to allocate normalized Story file.');
        }
        $output = $temporary.'.mp4';
        @unlink($temporary);
        $result = Process::timeout(180)->run([
            (string) config('services.telegram_business.story_ffmpeg_binary', 'ffmpeg'), '-y',
            '-ss', (string) $offset, '-i', $input, '-t', (string) min(self::STORY_SECONDS_MAX, round($length, 3)),
            '-filter_complex',
            '[0:v]split=2[background][foreground];[background]scale=720:1280:force_original_aspect_ratio=increase,crop=720:1280,boxblur=20:1[blurred];[foreground]scale=720:1280:force_original_aspect_ratio=decrease[fit];[blurred][fit]overlay=(W-w)/2:(H-h)/2',
            '-c:v', 'libx265', '-tag:v', 'hvc1', '-x265-params', 'keyint=30:min-keyint=30',
            '-c:a', 'aac', '-movflags', '+faststart', $output,
        ]);
        if (! $result->successful() || ! is_file($output) || filesize($output) > self::STORY_BYTES_MAX) {
            @unlink($output);
            throw new RuntimeException('Video could not be made into a Telegram Story (ffmpeg or 30 MB limit): '.mb_substr($result->errorOutput(), 0, 300));
        }

        return $output;
    }

    private function storyConnection(): TelegramBusinessConnection
    {
        $connection = TelegramBusinessConnection::query()->where('is_enabled', true)->get()
            ->first(fn (TelegramBusinessConnection $item): bool => (bool) ($item->rights['can_manage_stories'] ?? false));
        if ($connection === null) {
            throw new RuntimeException('No active Business connection with can_manage_stories.');
        }

        return $connection;
    }

    private function postStory(string $connectionId, string $video, float $duration, int $part, int $parts, ?string $link = null): int
    {
        $caption = (string) config('services.telegram_business.story_caption', '');
        if ($caption === '') {
            $caption = 'Видео из канала';
        }
        if ($parts > 1) {
            $caption = trim($caption.' ('.$part.'/'.$parts.')');
        }
        if ($link !== null) {
            $caption = trim($caption."\n".$link);
        }
        try {
            $payload = [
                'business_connection_id' => $connectionId,
                'content' => json_encode(['type' => 'video', 'video' => 'attach://video', 'duration' => min(60, $duration)]),
                'active_period' => (int) config('services.telegram_business.story_active_period', 86400),
                'caption' => $caption,
            ];
            if ($link !== null) {
                $payload['areas'] = TelegramStoryLink::area($link);
            }
            $response = Http::timeout(120)->attach('video', fopen($video, 'r'), 'story.mp4')->post(
                'https://api.telegram.org/bot'.$this->token().'/postStory',
                $payload,
            );
        } catch (ConnectionException $e) {
            throw new StoryUploadOutcomeUnknown('Telegram postStory transport outcome is unknown; review account Stories before retry.', previous: $e);
        }
        if ($response->serverError() || ($response->successful() && ! is_numeric($response->json('result.id')))) {
            throw new StoryUploadOutcomeUnknown('Telegram postStory response did not confirm an outcome; review account Stories before retry.');
        }
        if (! $response->successful() || ! is_numeric($response->json('result.id'))) {
            throw new RuntimeException('Telegram postStory failed: '.mb_substr($response->body(), 0, 500));
        }

        return (int) $response->json('result.id');
    }

    private function token(): string
    {
        $token = trim((string) config('services.telegram_business.token', ''));
        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BUSINESS_BOT_TOKEN is not configured.');
        }

        return $token;
    }
}
