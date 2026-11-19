<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessTelegramBusinessUpdate;
use App\Models\StoryPost;
use App\Services\TelegramBusiness\BusinessSupportReplyDrainer;
use App\Services\TelegramBusiness\TelegramBusinessNormalizer;
use App\Services\TelegramBusiness\TelegramBusinessStoryPublisher;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H6547: черновик видео-анонса уходит от ОСНОВНОГО бота (@samskrte_ops_bot),
 * чей вебхук = /api/webhooks/telegram-business → ProcessTelegramBusinessUpdate.
 * Reply MG заголовком приходит туда обычным `message` — джоб обязан отдать его
 * VideoAnonsStoriesService ДО бизнес-веток (живой промах 13-10: reply лег в
 * джоб и был молча уронен — StoryPost не создался).
 */
class ProcessTelegramBusinessUpdateVideoAnonsTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaPath;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'features.telegram_business_bot' => true,
            'video-anons.stories_enabled' => true,
            'services.telegram.bot_token' => 'MAIN-TOKEN',
            'services.telegram.admin_id' => '427307131',
            'app.url' => 'https://samskrte.ru',
        ]);
        $dir = storage_path('framework/testing/video-anons-bizhook-'.uniqid());
        mkdir($dir, 0777, true);
        $this->mediaPath = $dir.'/1067_video.mp4';
        file_put_contents($this->mediaPath, 'fake-mp4-bytes');

        Http::fake([
            'api.telegram.org/botMAIN-TOKEN/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 9100]]),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->mediaPath);
        @rmdir(dirname($this->mediaPath));
        parent::tearDown();
    }

    public function test_admin_reply_message_update_creates_approved_story_post(): void
    {
        $pending = json_encode([
            'source_chat_id' => -100111,
            'source_message_id' => 1067,
            'course' => 'leitana',
            'media_path' => $this->mediaPath,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        Redis::shouldReceive('set')->andReturn(true);
        Redis::shouldReceive('get')->once()->with('video-anons:pending:427307131:47')->andReturn($pending);
        Redis::shouldReceive('del')->once();

        (new ProcessTelegramBusinessUpdate([
            'update_id' => 77001,
            'message' => [
                'message_id' => 48,
                'date' => 1791910000,
                'chat' => ['id' => 427307131, 'type' => 'private'],
                'from' => ['id' => 427307131, 'first_name' => 'M'],
                'reply_to_message' => ['message_id' => 47, 'from' => ['id' => 777000, 'is_bot' => true]],
                'text' => 'Как звучит санскритская азбука',
            ],
        ]))->handle(
            app(TelegramBusinessNormalizer::class),
            app(TelegramSupportSyncService::class),
            app(BusinessSupportReplyDrainer::class),
            app(TelegramBusinessStoryPublisher::class),
        );

        $post = StoryPost::query()->sole();
        $this->assertSame(StoryPost::STATUS_APPROVED, $post->status);
        $this->assertSame(StoryPost::KIND_VIDEO, $post->kind);
        $this->assertStringContainsString('Как звучит санскритская азбука', (string) $post->payload);
    }

    public function test_non_admin_message_update_falls_through_untouched(): void
    {
        Redis::shouldReceive('get')->never();

        (new ProcessTelegramBusinessUpdate([
            'update_id' => 77002,
            'message' => [
                'message_id' => 49,
                'date' => 1791910000,
                'chat' => ['id' => 999, 'type' => 'private'],
                'from' => ['id' => 999],
                'text' => 'привет',
            ],
        ]))->handle(
            app(TelegramBusinessNormalizer::class),
            app(TelegramSupportSyncService::class),
            app(BusinessSupportReplyDrainer::class),
            app(TelegramBusinessStoryPublisher::class),
        );

        $this->assertSame(0, StoryPost::query()->count());
    }
}
