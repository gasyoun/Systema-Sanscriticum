<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarketingSetting;
use App\Models\StoryPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * stories:publish-due (H3930, Phase 1): флаг OFF — ноль HTTP; текстовые
 * approved+due строки уходят магнит-ботом после getChat-пробы (FINDINGS §651);
 * photo/video скипаются с журналом до Phase 2; проба не прошла — ничего не шлём.
 */
class StoriesPublishDueTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<Request> */
    public static array $sent = [];

    /** Http::fake аппендит стабы (numeric-key merge), повторный fake не
     * перекрывает setUp-стаб — управляем поведением флагом, а не вторым fake. */
    public static bool $probeFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        self::$sent = [];
        self::$probeFails = false;
        Http::fake(function (Request $request) {
            self::$sent[] = $request;

            if (self::$probeFails) {
                return Http::response(['ok' => false, 'error_code' => 400, 'description' => 'chat not found'], 400);
            }

            return str_contains($request->url(), '/getChat')
                ? Http::response(['ok' => true, 'result' => ['id' => -100123]])
                : Http::response(['ok' => true, 'result' => ['message_id' => 42]]);
        });

        config([
            'features.telegram_story_publisher' => false,
            'services.telegram_story.channel_chat_id' => '@rusamskrtam',
        ]);

        MarketingSetting::create(['tg_bot_token' => 'TESTMAGNETTOKEN']);
        MarketingSetting::flushCached();
    }

    /** @test */
    public function flag_off_means_no_http_and_no_state_change(): void
    {
        $post = StoryPost::query()->create([
            'kind' => StoryPost::KIND_TEXT,
            'payload' => 'Текст',
            'status' => StoryPost::STATUS_APPROVED,
            'publish_at' => now()->subMinute(),
        ]);

        $this->artisan('stories:publish-due')
            ->expectsOutputToContain('no-op')
            ->assertSuccessful();

        self::assertSame([], self::$sent);
        $this->assertSame(StoryPost::STATUS_APPROVED, $post->fresh()->status);
    }

    /** @test */
    public function publishes_due_text_post_via_magnet_bot_after_probe(): void
    {
        config(['features.telegram_story_publisher' => true]);

        $post = StoryPost::query()->create([
            'kind' => StoryPost::KIND_TEXT,
            'payload' => 'Текст с "кавычками" & <скобками>',
            'source' => StoryPost::SOURCE_QUEUE,
            'source_key' => '2026-09-05-ANN9.md',
            'status' => StoryPost::STATUS_APPROVED,
            'publish_at' => now()->subHour(),
        ]);

        $this->artisan('stories:publish-due')->assertSuccessful();

        self::assertCount(2, self::$sent, 'getChat probe + one sendMessage');
        self::assertTrue(str_contains(self::$sent[0]->url(), '/getChat'));
        self::assertTrue(str_contains(self::$sent[1]->url(), '/botTESTMAGNETTOKEN/sendMessage'), 'magnet bot credential, not cabinet/zapisi bots');

        $fresh = $post->fresh();
        $this->assertSame(StoryPost::STATUS_PUBLISHED, $fresh->status);
        $this->assertNotNull($fresh->posted_at);
    }

    /** @test */
    public function probe_failure_refuses_any_send(): void
    {
        config(['features.telegram_story_publisher' => true]);

        self::$probeFails = true;

        $post = StoryPost::query()->create([
            'kind' => StoryPost::KIND_TEXT,
            'payload' => 'Текст',
            'status' => StoryPost::STATUS_APPROVED,
            'publish_at' => now()->subMinute(),
        ]);

        $exitCode = Artisan::call('stories:publish-due');
        $out = Artisan::output();
        self::assertSame(1, $exitCode, "exit={$exitCode}, sent=".count(self::$sent).', row_status='.$post->fresh()->status.", out:\n".$out);

        self::assertCount(1, self::$sent, 'only the probe ran');
        $this->assertSame(StoryPost::STATUS_APPROVED, $post->fresh()->status);
    }

    /** @test */
    public function media_rows_are_skipped_with_journal_note(): void
    {
        config(['features.telegram_story_publisher' => true]);

        $photo = StoryPost::query()->create([
            'kind' => StoryPost::KIND_PHOTO,
            'payload' => 'Подпись',
            'media_path' => 'harvest/peer/x.jpg',
            'status' => StoryPost::STATUS_APPROVED,
            'publish_at' => now()->subMinute(),
        ]);

        $this->artisan('stories:publish-due')->assertSuccessful();

        self::assertCount(1, self::$sent, 'probe only, no sendMessage');
        $this->assertSame(StoryPost::STATUS_APPROVED, $photo->fresh()->status);
        $this->assertStringContainsString('Phase 2', (string) $photo->fresh()->journal);
    }
}
