<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StoryPost;
use App\Services\Anons\VideoAnons\VideoAnonsStoriesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H6547: ответ MG заголовком на черновик видео-анонса → approved-строка
 * StoryPost (lane=persona, kind=video) — публикация существующим
 * stories:publish-story. Заголовок MG = виза; idемпотентность по source_key.
 */
class VideoAnonsStoriesTest extends TestCase
{
    use RefreshDatabase;

    private const MG_CHAT = 427307131;

    private const DRAFT_MSG = 5002;

    private string $mediaPath;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'video-anons.stories_enabled' => true,
            'services.telegram.bot_token' => 'MAIN-TOKEN',
            'services.telegram.admin_id' => (string) self::MG_CHAT,
            'app.url' => 'https://samskrte.ru',
        ]);
        $dir = storage_path('framework/testing/video-anons-stories-'.uniqid());
        File::ensureDirectoryExists($dir);
        $this->mediaPath = $dir.'/1067_video.mp4';
        File::put($this->mediaPath, 'fake-mp4-bytes');

        Http::fake([
            'api.telegram.org/botMAIN-TOKEN/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 9001]]),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->mediaPath));
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function replyMessage(string $text = 'Как звучит санскритская азбука', int $fromId = self::MG_CHAT): array
    {
        return [
            'message_id' => 6001,
            'date' => 1791823306,
            'chat' => ['id' => self::MG_CHAT, 'type' => 'private'],
            'from' => ['id' => $fromId, 'first_name' => 'M'],
            'reply_to_message' => ['message_id' => self::DRAFT_MSG, 'from' => ['id' => 777000, 'is_bot' => true]],
            'text' => $text,
        ];
    }

    private function pendingContext(?string $mediaPath = null): string
    {
        return (string) json_encode([
            'source_chat_id' => -100111,
            'source_message_id' => 1067,
            'course' => 'leitana',
            'media_path' => $mediaPath ?? $this->mediaPath,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function test_admin_reply_with_headline_creates_approved_persona_story_post(): void
    {
        Redis::shouldReceive('get')->once()->with('video-anons:pending:'.self::MG_CHAT.':'.self::DRAFT_MSG)
            ->andReturn($this->pendingContext());
        Redis::shouldReceive('del')->once();

        $consumed = (new VideoAnonsStoriesService)->handleAdminReply($this->replyMessage());

        $this->assertTrue($consumed);

        $post = StoryPost::query()->sole();
        $this->assertSame(StoryPost::STATUS_APPROVED, $post->status);
        $this->assertSame(StoryPost::LANE_PERSONA, $post->lane);
        $this->assertSame(StoryPost::KIND_VIDEO, $post->kind);
        $this->assertSame(StoryPost::SOURCE_HARVEST, $post->source);
        $this->assertSame('harvest:'.md5($this->mediaPath), $post->source_key);
        $this->assertSame($this->mediaPath, $post->media_path);
        $this->assertStringContainsString('Как звучит санскритская азбука', (string) $post->payload);
        $this->assertStringContainsString('https://samskrte.ru/ga/vanons-tg-v1', (string) $post->payload);
        $this->assertNotNull($post->publish_at);
        $this->assertTrue(StoryPost::query()->approved()->due()->whereKey($post->id)->exists(), 'строка должна быть approved+due для планировщика');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botMAIN-TOKEN/sendMessage')
            && str_contains((string) $request['text'], 'Сторис поставлена в очередь'));
    }

    public function test_second_reply_is_idempotent_by_source_key(): void
    {
        Redis::shouldReceive('get')->twice()->andReturn($this->pendingContext());

        (new VideoAnonsStoriesService)->handleAdminReply($this->replyMessage());
        (new VideoAnonsStoriesService)->handleAdminReply($this->replyMessage('Другой заголовок'));

        $this->assertSame(1, StoryPost::query()->count());
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'уже в очереди'));
    }

    public function test_non_admin_reply_returns_false(): void
    {
        Redis::shouldReceive('get')->never();

        $consumed = (new VideoAnonsStoriesService)->handleAdminReply($this->replyMessage(fromId: 999));

        $this->assertFalse($consumed);
        $this->assertSame(0, StoryPost::query()->count());
    }

    public function test_reply_without_pending_context_returns_false(): void
    {
        Redis::shouldReceive('get')->andReturn(null);

        $consumed = (new VideoAnonsStoriesService)->handleAdminReply($this->replyMessage());

        $this->assertFalse($consumed);
        $this->assertSame(0, StoryPost::query()->count());
    }

    public function test_stories_flag_off_returns_false(): void
    {
        config(['video-anons.stories_enabled' => false]);
        Redis::shouldReceive('get')->never();

        $consumed = (new VideoAnonsStoriesService)->handleAdminReply($this->replyMessage());

        $this->assertFalse($consumed);
    }

    public function test_missing_media_file_replies_failure_without_story_post(): void
    {
        Redis::shouldReceive('get')->once()->andReturn($this->pendingContext(mediaPath: '/nonexistent/1067_video.mp4'));

        $consumed = (new VideoAnonsStoriesService)->handleAdminReply($this->replyMessage());

        $this->assertTrue($consumed);
        $this->assertSame(0, StoryPost::query()->count());
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'не найден'));
    }

    public function test_empty_headline_gets_hint_without_story_post(): void
    {
        Redis::shouldReceive('get')->once()->andReturn($this->pendingContext());

        $consumed = (new VideoAnonsStoriesService)->handleAdminReply($this->replyMessage(text: '   '));

        $this->assertTrue($consumed);
        $this->assertSame(0, StoryPost::query()->count());
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Пришлите заголовок'));
    }

    public function test_too_long_headline_gets_hint_without_story_post(): void
    {
        Redis::shouldReceive('get')->once()->andReturn($this->pendingContext());

        $consumed = (new VideoAnonsStoriesService)->handleAdminReply($this->replyMessage(text: str_repeat('з', 201)));

        $this->assertTrue($consumed);
        $this->assertSame(0, StoryPost::query()->count());
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Пришлите заголовок'));
    }
}
