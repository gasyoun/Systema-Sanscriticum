<?php

declare(strict_types=1);

namespace Tests\Feature\TelegramBusiness;

use App\Models\TelegramBusinessConnection;
use App\Models\TelegramBusinessStoryPublication;
use App\Services\TelegramBusiness\TelegramBusinessStoryPublisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

final class TelegramBusinessStoryPartsTest extends TestCase
{
    public function test_long_video_posts_three_ordered_parts_and_saves_each_story_id(): void
    {
        if (! Process::run(['ffmpeg', '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg is required for this media acceptance test.');
        }

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
            $table->unsignedBigInteger('story_id')->nullable();
            $table->json('story_ids')->nullable();
            $table->unsignedSmallInteger('part_count')->nullable();
            $table->string('status');
            $table->timestamps();
        });
        TelegramBusinessConnection::create([
            'business_connection_id' => 'test-connection',
            'is_enabled' => true,
            'rights' => ['can_manage_stories' => true],
        ]);
        $ledger = TelegramBusinessStoryPublication::create([
            'source_chat_id' => '-1001', 'source_message_id' => 620, 'status' => 'received',
        ]);
        $source = tempnam(sys_get_temp_dir(), 'tg-story-test-').'.mp4';
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
                $requests[] = [
                    'caption' => $request['caption'],
                    'content' => json_decode($request['content'], true),
                ];

                return Http::response(['ok' => true, 'result' => ['id' => 700 + count($requests)]]);
            });

            $method = new ReflectionMethod(TelegramBusinessStoryPublisher::class, 'publishParts');
            $method->invoke(app(TelegramBusinessStoryPublisher::class), $ledger, $source);

            self::assertSame([701, 702, 703], $ledger->fresh()->story_ids);
            self::assertSame(701, $ledger->fresh()->story_id);
            self::assertSame(3, $ledger->fresh()->part_count);
            self::assertSame('published', $ledger->fresh()->status);
            self::assertSame(['Video (1/3)', 'Video (2/3)', 'Video (3/3)'], array_column($requests, 'caption'));
            self::assertSame([60, 60, 1], array_column(array_column($requests, 'content'), 'duration'));
        } finally {
            @unlink($source);
        }
    }
}
