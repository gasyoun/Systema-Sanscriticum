<?php

declare(strict_types=1);

namespace Tests\Feature\TelegramBusiness;

use App\Jobs\PublishTelegramBusinessStory;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramBusinessStoryPublication;
use App\Services\TelegramBusiness\StoryUploadOutcomeUnknown;
use App\Services\TelegramBusiness\TelegramBusinessStoryPublisher;
use App\Services\TelegramBusiness\TelegramStoryVideoFingerprint;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

final class TelegramBusinessStoryPartsTest extends TestCase
{
    public function test_long_video_posts_three_ordered_parts_and_saves_each_story_id(): void
    {
        $encoders = Process::run(['ffmpeg', '-hide_banner', '-encoders']);
        if (! $encoders->successful() || ! str_contains($encoders->output(), 'libx265')) {
            $this->markTestSkipped('ffmpeg with libx265 is required for this media acceptance test.');
        }

        $this->createPublisherTables();
        $ledger = TelegramBusinessStoryPublication::create([
            'source_chat_id' => '-1001', 'source_message_id' => 620, 'status' => 'received',
            'cta_url' => 'https://t.me/samskrte/620',
        ]);
        $temporary = tempnam(sys_get_temp_dir(), 'tg-story-test-');
        self::assertNotFalse($temporary);
        @unlink($temporary);
        $source = $temporary.'.mp4';
        try {
            $generated = Process::timeout(30)->run([
                'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi',
                '-i', 'testsrc2=size=180x320:rate=1', '-t', '121', '-c:v', 'libx264',
                '-preset', 'ultrafast', $source,
            ]);
            self::assertTrue($generated->successful(), $generated->errorOutput());

            config()->set('services.telegram_business.token', 'test-token');
            config()->set('services.telegram_business.story_caption', 'Video');
            $requests = [];
            Http::fake(function ($request) use (&$requests) {
                $requests[] = collect($request->data())->pluck('contents', 'name')->all();

                return Http::response(['ok' => true, 'result' => ['id' => 700 + count($requests)]]);
            });

            $method = new ReflectionMethod(TelegramBusinessStoryPublisher::class, 'publishParts');
            self::assertTrue($method->invoke(app(TelegramBusinessStoryPublisher::class), $ledger, $source, 1));
            self::assertSame([701], $ledger->fresh()->story_ids);
            self::assertSame('partial', $ledger->fresh()->status);
            self::assertFalse($method->invoke(app(TelegramBusinessStoryPublisher::class), $ledger->fresh(), $source));

            self::assertSame([701, 702, 703], $ledger->fresh()->story_ids);
            self::assertSame(701, $ledger->fresh()->story_id);
            self::assertSame(3, $ledger->fresh()->part_count);
            self::assertSame('published', $ledger->fresh()->status);
            self::assertSame([
                "Video (1/3)\nhttps://t.me/samskrte/620",
                "Video (2/3)\nhttps://t.me/samskrte/620",
                "Video (3/3)\nhttps://t.me/samskrte/620",
            ], array_column($requests, 'caption'));
            self::assertSame('https://t.me/samskrte/620', json_decode($requests[0]['areas'], true)[0]['type']['url']);
            self::assertSame([60, 60, 1], array_map(fn ($request) => json_decode($request['content'], true)['duration'], $requests));
        } finally {
            @unlink($source);
        }
    }

    public function test_unconfirmed_upload_is_not_treated_as_a_safe_retry(): void
    {
        config()->set('services.telegram_business.token', 'test-token');
        Http::fake(['*' => Http::response(['ok' => false], 503)]);
        $video = tempnam(sys_get_temp_dir(), 'tg-story-unknown-');
        self::assertNotFalse($video);

        try {
            $method = new ReflectionMethod(TelegramBusinessStoryPublisher::class, 'postStory');
            $this->expectException(StoryUploadOutcomeUnknown::class);
            $method->invoke(app(TelegramBusinessStoryPublisher::class), 'test-connection', $video, 10.0, 1, 1);
        } finally {
            @unlink($video);
        }
    }

    public function test_approved_subtitles_are_burned_into_a_story_part_when_ffmpeg_supports_libass(): void
    {
        $filters = Process::run(['ffmpeg', '-hide_banner', '-filters']);
        $encoders = Process::run(['ffmpeg', '-hide_banner', '-encoders']);
        if (! $filters->successful() || ! str_contains($filters->output(), ' subtitles ')
            || ! $encoders->successful() || ! str_contains($encoders->output(), 'libx265')) {
            $this->markTestSkipped('ffmpeg with subtitles and libx265 is required.');
        }
        $base = tempnam(sys_get_temp_dir(), 'tg-story-caption-test-');
        self::assertNotFalse($base);
        @unlink($base);
        $source = $base.'.mp4';
        $result = null;
        try {
            self::assertTrue(Process::timeout(30)->run([
                'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi',
                '-i', 'testsrc2=size=180x320:rate=10', '-t', '2', '-c:v', 'libx264', $source,
            ])->successful());
            $method = new ReflectionMethod(TelegramBusinessStoryPublisher::class, 'normalise');
            $result = $method->invoke(app(TelegramBusinessStoryPublisher::class), $source, 0, 2.0,
                "1\n00:00:00,000 --> 00:00:01,500\nСанскрит — это язык.\n");
            self::assertFileExists($result);
            self::assertGreaterThan(0, filesize($result));
        } finally {
            @unlink($source);
            if (is_string($result)) {
                @unlink($result);
            }
        }
    }

    public function test_daily_cap_defers_a_new_video_without_downloading_it(): void
    {
        $this->createPublisherTables();
        config()->set('services.telegram_business.story_daily_video_cap', 1);
        TelegramBusinessStoryPublication::create([
            'source_chat_id' => '-1001', 'source_message_id' => 620,
            'status' => 'published', 'started_at' => now(),
        ]);
        Queue::fake();
        Http::fake();

        app(TelegramBusinessStoryPublisher::class)->publishFromChannelPost([
            'chat' => ['id' => -1001, 'username' => 'samskrte'],
            'message_id' => 621,
            'video' => ['file_id' => 'not-downloaded', 'file_unique_id' => 'unique-621'],
        ]);

        $deferred = TelegramBusinessStoryPublication::query()->where('source_message_id', 621)->firstOrFail();
        self::assertSame('deferred', $deferred->status);
        self::assertNotNull($deferred->deferred_until);
        self::assertNull($deferred->started_at);
        Queue::assertPushed(PublishTelegramBusinessStory::class, 1);
        Http::assertNothingSent();
    }

    public function test_near_match_is_held_for_review_without_consuming_a_daily_slot(): void
    {
        $this->createPublisherTables();
        $fingerprint = ['duration' => 10.0, 'frames' => array_fill(0, 3, 'aaaaaaaaaaaaaaaa')];
        $published = TelegramBusinessStoryPublication::create([
            'source_chat_id' => '-1001', 'source_message_id' => 620,
            'status' => 'published', 'video_fingerprint' => $fingerprint,
        ]);
        $incoming = TelegramBusinessStoryPublication::create([
            'source_chat_id' => '-1002', 'source_message_id' => 621,
            'status' => 'received', 'started_at' => now(),
        ]);
        $post = [
            'chat' => ['id' => -1002, 'username' => 'samskrtamru'],
            'message_id' => 621,
            'video' => ['file_id' => 'file-id', 'file_unique_id' => 'unique-id'],
            'caption' => 'A public caption',
        ];

        $method = new ReflectionMethod(TelegramBusinessStoryPublisher::class, 'holdNearMatchForReview');
        self::assertTrue($method->invoke(app(TelegramBusinessStoryPublisher::class), $incoming,
            $fingerprint, str_repeat('a', 64), $post, app(TelegramStoryVideoFingerprint::class)));

        $incoming->refresh();
        self::assertSame('review', $incoming->status);
        self::assertSame($published->id, $incoming->duplicate_of_id);
        self::assertNull($incoming->started_at);
        self::assertSame($post['video'], $incoming->source_post['video']);
        self::assertSame('A public caption', $incoming->source_post['caption']);
        self::assertEmpty($incoming->story_ids);
    }

    public function test_known_failed_part_resumes_without_reposting_completed_part(): void
    {
        $encoders = Process::run(['ffmpeg', '-hide_banner', '-encoders']);
        if (! $encoders->successful() || ! str_contains($encoders->output(), 'libx265')) {
            $this->markTestSkipped('ffmpeg with libx265 is required for this media acceptance test.');
        }

        $this->createPublisherTables();
        $ledger = TelegramBusinessStoryPublication::create([
            'source_chat_id' => '-1001', 'source_message_id' => 621, 'status' => 'received',
        ]);
        $temporary = tempnam(sys_get_temp_dir(), 'tg-story-retry-');
        self::assertNotFalse($temporary);
        @unlink($temporary);
        $source = $temporary.'.mp4';

        try {
            $generated = Process::timeout(30)->run([
                'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi',
                '-i', 'testsrc2=size=180x320:rate=1', '-t', '61', '-c:v', 'libx264',
                '-preset', 'ultrafast', $source,
            ]);
            self::assertTrue($generated->successful(), $generated->errorOutput());
            config()->set('services.telegram_business.token', 'test-token');
            $calls = 0;
            Http::fake(function () use (&$calls) {
                $calls++;

                return $calls === 2
                    ? Http::response(['ok' => false, 'description' => 'Too Many Requests'], 429)
                    : Http::response(['ok' => true, 'result' => ['id' => 800 + $calls]]);
            });

            $method = new ReflectionMethod(TelegramBusinessStoryPublisher::class, 'publishParts');
            try {
                $method->invoke(app(TelegramBusinessStoryPublisher::class), $ledger, $source);
                self::fail('The second upload should have been rejected by Telegram.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Telegram postStory failed', $e->getMessage());
            }
            self::assertSame([801], $ledger->fresh()->story_ids);
            self::assertSame('partial', $ledger->fresh()->status);

            $method->invoke(app(TelegramBusinessStoryPublisher::class), $ledger->fresh(), $source);
            self::assertSame(3, $calls);
            self::assertSame([801, 803], $ledger->fresh()->story_ids);
            self::assertSame('published', $ledger->fresh()->status);
        } finally {
            @unlink($source);
        }
    }

    private function createPublisherTables(): void
    {
        Schema::create('telegram_business_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('business_connection_id');
            $table->boolean('is_enabled');
            $table->json('rights');
            $table->timestamps();
        });
        Schema::create('telegram_business_story_publications', function (Blueprint $table): void {
            $table->id();
            $table->string('source_chat_id');
            $table->unsignedBigInteger('source_message_id');
            $table->string('telegram_file_unique_id')->nullable();
            $table->string('media_sha256')->nullable()->unique();
            $table->unsignedBigInteger('duplicate_of_id')->nullable();
            $table->unsignedBigInteger('story_id')->nullable();
            $table->json('story_ids')->nullable();
            $table->unsignedSmallInteger('part_count')->nullable();
            $table->string('cta_url')->nullable();
            $table->json('video_fingerprint')->nullable();
            $table->json('source_post')->nullable();
            $table->timestamp('near_match_approved_at')->nullable();
            $table->string('source_media_path')->nullable();
            $table->longText('subtitle_draft')->nullable();
            $table->string('subtitle_status')->nullable();
            $table->timestamp('last_story_posted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('deferred_until')->nullable();
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestamps();
        });
        TelegramBusinessConnection::create([
            'business_connection_id' => 'test-connection',
            'is_enabled' => true,
            'rights' => ['can_manage_stories' => true],
        ]);
    }
}
