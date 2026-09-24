<?php

declare(strict_types=1);

namespace App\Services\TelegramBusiness;

use App\Models\TelegramBusinessConnection;
use App\Models\TelegramBusinessStoryPublication;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/** Publishes one video from an approved channel as a Business-account Story. */
final class TelegramBusinessStoryPublisher
{
    private const STORY_BYTES_MAX = 30_000_000;

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
        if (! $ledger->wasRecentlyCreated) {
            return;
        }

        $tmp = null;
        $storyVideo = null;
        try {
            $tmp = $this->download((string) ($video['file_id'] ?? ''));
            $hash = hash_file('sha256', $tmp);
            $prior = TelegramBusinessStoryPublication::query()
                ->where('media_sha256', $hash)->where('id', '!=', $ledger->id)->first();
            if ($prior !== null) {
                // media_sha256 is unique: keep it on the first publisher and
                // point this later source message at that authoritative row.
                $ledger->update(['duplicate_of_id' => $prior->id, 'status' => 'duplicate']);

                return;
            }

            $storyVideo = $this->normalise($tmp);
            $connection = $this->storyConnection();
            $storyId = $this->postStory($connection->business_connection_id, $storyVideo);
            $ledger->update(['media_sha256' => $hash, 'story_id' => $storyId, 'status' => 'published']);
        } catch (Throwable $e) {
            $ledger->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            Log::warning('Telegram Business Story publish failed', ['publication_id' => $ledger->id, 'error' => $e->getMessage()]);
        } finally {
            foreach (array_filter([$tmp, $storyVideo]) as $path) {
                @unlink($path);
            }
        }
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

    private function download(string $fileId): string
    {
        if ($fileId === '') {
            throw new RuntimeException('Source video has no Telegram file_id.');
        }
        $token = $this->token();
        $meta = Http::timeout(20)->get("https://api.telegram.org/bot{$token}/getFile", ['file_id' => $fileId]);
        $path = $meta->json('result.file_path');
        if (! $meta->successful() || ! is_string($path) || $path === '') {
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

    private function normalise(string $input): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'tg-story-out-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to allocate normalized Story file.');
        }
        $output = $temporary.'.mp4';
        @unlink($temporary);
        $result = Process::timeout(180)->run([
            (string) config('services.telegram_business.story_ffmpeg_binary', 'ffmpeg'), '-y', '-i', $input,
            '-t', '60', '-vf', 'scale=720:1280:force_original_aspect_ratio=increase,crop=720:1280',
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

    private function postStory(string $connectionId, string $video): int
    {
        $response = Http::timeout(120)->attach('video', fopen($video, 'r'), 'story.mp4')->post(
            'https://api.telegram.org/bot'.$this->token().'/postStory',
            [
                'business_connection_id' => $connectionId,
                'content' => json_encode(['type' => 'video', 'video' => 'attach://video', 'duration' => 60]),
                'active_period' => (int) config('services.telegram_business.story_active_period', 86400),
                'caption' => (string) config('services.telegram_business.story_caption', ''),
            ],
        );
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
