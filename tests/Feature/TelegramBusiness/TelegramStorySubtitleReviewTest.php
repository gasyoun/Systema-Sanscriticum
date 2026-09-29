<?php

declare(strict_types=1);

namespace Tests\Feature\TelegramBusiness;

use App\Jobs\PublishTelegramBusinessStory;
use App\Models\TelegramBusinessStoryPublication;
use App\Services\TelegramBusiness\TelegramStorySubtitleReview;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class TelegramStorySubtitleReviewTest extends TestCase
{
    public function test_empty_day_publishes_immediately_without_staging_a_draft(): void
    {
        $this->table();
        config()->set('services.telegram_business.story_subtitles_enabled', true);
        $incoming = $this->publication(1, 'received');
        $source = tempnam(sys_get_temp_dir(), 'story-subtitle-test-');
        self::assertNotFalse($source);
        try {
            file_put_contents($source, 'public channel media');
            self::assertFalse(app(TelegramStorySubtitleReview::class)->holdIfCovered($incoming, $this->sourcePost(1), $source));
            self::assertSame('received', $incoming->fresh()->status);
            self::assertNull($incoming->fresh()->source_media_path);
        } finally {
            @unlink($source);
        }
    }

    public function test_covered_day_holds_video_for_seven_days_and_releases_daily_slot(): void
    {
        $this->table();
        config()->set('services.telegram_business.story_subtitles_enabled', true);
        $this->publication(1, 'published', ['last_story_posted_at' => now()->subHour()]);
        $incoming = $this->publication(2, 'received', ['started_at' => now()]);
        $source = tempnam(sys_get_temp_dir(), 'story-subtitle-test-');
        self::assertNotFalse($source);
        try {
            file_put_contents($source, 'public channel media');
            self::assertTrue(app(TelegramStorySubtitleReview::class)->holdIfCovered($incoming, $this->sourcePost(2), $source));
            $incoming->refresh();
            self::assertSame('subtitle_pending', $incoming->status);
            self::assertSame('pending', $incoming->subtitle_status);
            self::assertNull($incoming->started_at);
            self::assertEquals(7, $incoming->subtitle_requested_at->diffInDays($incoming->subtitle_deadline_at));
            self::assertSame('public channel media', File::get($incoming->source_media_path));
        } finally {
            @unlink($source);
            if (is_string($incoming->source_media_path)) {
                File::delete($incoming->source_media_path);
            }
        }
    }

    public function test_gap_releases_oldest_original_even_before_review_deadline(): void
    {
        $this->table();
        Queue::fake();
        $older = $this->publication(1, 'subtitle_pending', [
            'subtitle_status' => 'ready', 'subtitle_requested_at' => now()->subDay(),
            'subtitle_deadline_at' => now()->addDays(6), 'source_post' => $this->sourcePost(1),
        ]);
        $newer = $this->publication(2, 'subtitle_pending', [
            'subtitle_status' => 'pending', 'subtitle_requested_at' => now(),
            'subtitle_deadline_at' => now()->addDays(7), 'source_post' => $this->sourcePost(2),
        ]);

        self::assertSame(1, app(TelegramStorySubtitleReview::class)->releaseDue());
        self::assertSame('subtitle_release', $older->fresh()->status);
        self::assertSame('subtitle_pending', $newer->fresh()->status);
        self::assertSame(0, app(TelegramStorySubtitleReview::class)->releaseDue());
        Queue::assertPushed(PublishTelegramBusinessStory::class, 1);
    }

    public function test_seven_day_deadline_releases_original_despite_existing_coverage(): void
    {
        $this->table();
        Queue::fake();
        $this->publication(1, 'published', ['last_story_posted_at' => now()]);
        $overdue = $this->publication(2, 'subtitle_pending', [
            'subtitle_status' => 'ready', 'subtitle_requested_at' => now()->subDays(7),
            'subtitle_deadline_at' => now()->subMinute(), 'source_post' => $this->sourcePost(2),
        ]);

        self::assertSame(1, app(TelegramStorySubtitleReview::class)->releaseDue());
        self::assertSame('subtitle_release', $overdue->fresh()->status);
        Queue::assertPushed(PublishTelegramBusinessStory::class, 1);
    }

    public function test_worker_media_download_requires_a_short_lived_signature(): void
    {
        $this->table();
        $source = tempnam(sys_get_temp_dir(), 'story-subtitle-signed-');
        self::assertNotFalse($source);
        try {
            file_put_contents($source, 'public channel media');
            $ledger = $this->publication(1, 'subtitle_pending', [
                'subtitle_status' => 'pending', 'source_media_path' => $source,
            ]);
            $path = '/internal/telegram-story-subtitle-media/'.$ledger->id;
            $this->get($path)->assertForbidden();
            $signed = URL::temporarySignedRoute('telegram-story-subtitle-media', now()->addMinutes(5),
                ['publication' => $ledger->id], absolute: false);
            $this->get($signed)->assertOk();
        } finally {
            @unlink($source);
        }
    }

    private function table(): void
    {
        Schema::create('telegram_business_story_publications', function (Blueprint $table): void {
            $table->id();
            $table->string('source_chat_id');
            $table->unsignedBigInteger('source_message_id');
            $table->string('status');
            $table->string('subtitle_status')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('deferred_until')->nullable();
            $table->timestamp('last_story_posted_at')->nullable();
            $table->timestamp('subtitle_requested_at')->nullable();
            $table->timestamp('subtitle_deadline_at')->nullable();
            $table->string('source_media_path')->nullable();
            $table->json('source_post')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    private function publication(int $messageId, string $status, array $attributes = []): TelegramBusinessStoryPublication
    {
        return TelegramBusinessStoryPublication::create(array_merge([
            'source_chat_id' => '-1001', 'source_message_id' => $messageId, 'status' => $status,
        ], $attributes));
    }

    private function sourcePost(int $messageId): array
    {
        return ['chat' => ['id' => -1001, 'username' => 'samskrte'],
            'message_id' => $messageId, 'video' => ['file_id' => 'test']];
    }
}
