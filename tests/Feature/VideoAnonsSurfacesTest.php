<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PublishVideoAnonsVkWallJob;
use App\Jobs\UploadVideoAnonsShortJob;
use App\Models\StoryPost;
use App\Services\Anons\VideoAnons\VideoAnonsStoriesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H6574: одна виза MG (заголовок-ответ) → три поверхности за своими флагами:
 * сторис (H6547), YouTube Shorts (UploadVideoAnonsShortJob), VK-стена
 * (PublishVideoAnonsVkWallJob, гейт авторизованных групп, fail-closed).
 */
class VideoAnonsSurfacesTest extends TestCase
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
        $dir = storage_path('framework/testing/video-anons-surfaces-'.uniqid());
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

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function pendingContext(array $extra = []): array
    {
        return array_merge([
            'source_chat_id' => -100111,
            'source_message_id' => 1067,
            'course' => 'leitana',
            'media_path' => $this->mediaPath,
        ], $extra);
    }

    private function mockPending(array $context, int $times = 1): void
    {
        Redis::shouldReceive('get')->times($times)
            ->with('video-anons:pending:'.self::MG_CHAT.':'.self::DRAFT_MSG)
            ->andReturn((string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Redis::shouldReceive('del')->zeroOrMoreTimes();
    }

    private function mockClaim(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        Redis::shouldReceive('expire')->andReturn(true);
        Redis::shouldReceive('del')->andReturn(true);
    }

    public function test_headline_reply_dispatches_shorts_job_when_flag_on(): void
    {
        config(['video-anons.yt_shorts_enabled' => true]);
        Queue::fake();
        $this->mockPending($this->pendingContext());

        $consumed = (new VideoAnonsStoriesService)->handleAdminReply($this->replyMessage());

        $this->assertTrue($consumed);
        Queue::assertPushed(UploadVideoAnonsShortJob::class, fn (UploadVideoAnonsShortJob $job): bool => $job->sourceChatId === -100111
            && $job->sourceMessageId === 1067
            && $job->courseKey === 'leitana'
            && $job->headline === 'Как звучит санскритская азбука');
        $this->assertSame(1, StoryPost::query()->count());
        Http::assertSent(fn (Request $request): bool => str_contains(($request['text'] ?? ''), 'Shorts: джоб загрузки в очереди'));
    }

    public function test_headline_reply_dispatches_vk_job_when_flag_on(): void
    {
        config(['video-anons.vk_wall_enabled' => true]);
        Queue::fake();
        $this->mockPending($this->pendingContext());

        $consumed = (new VideoAnonsStoriesService)->handleAdminReply($this->replyMessage());

        $this->assertTrue($consumed);
        Queue::assertPushed(PublishVideoAnonsVkWallJob::class);
        Http::assertSent(fn (Request $request): bool => str_contains(($request['text'] ?? ''), 'VK: джоб поста в очереди'));
    }

    public function test_flags_off_dispatches_nothing(): void
    {
        Queue::fake();
        $this->mockPending($this->pendingContext());

        $consumed = (new VideoAnonsStoriesService)->handleAdminReply($this->replyMessage());

        $this->assertTrue($consumed);
        Queue::assertNothingPushed();
        $this->assertSame(1, StoryPost::query()->count());
    }

    public function test_stories_off_shorts_on_accepts_headline_without_story(): void
    {
        config([
            'video-anons.stories_enabled' => false,
            'video-anons.yt_shorts_enabled' => true,
        ]);
        Queue::fake();
        $this->mockPending($this->pendingContext());

        $consumed = (new VideoAnonsStoriesService)->handleAdminReply($this->replyMessage());

        $this->assertTrue($consumed);
        Queue::assertPushed(UploadVideoAnonsShortJob::class);
        $this->assertSame(0, StoryPost::query()->count());
        Http::assertSent(fn (Request $request): bool => str_contains(($request['text'] ?? ''), 'Виза заголовка принята'));
    }

    public function test_duplicate_story_reply_does_not_redispatch_surfaces(): void
    {
        config(['video-anons.yt_shorts_enabled' => true]);
        Queue::fake();
        $this->mockPending($this->pendingContext(), times: 2);

        (new VideoAnonsStoriesService)->handleAdminReply($this->replyMessage());
        (new VideoAnonsStoriesService)->handleAdminReply($this->replyMessage('Другой заголовок'));

        Queue::assertPushed(UploadVideoAnonsShortJob::class, 1);
    }

    public function test_shorts_job_refuses_without_credentials(): void
    {
        config(['video-anons.yt_shorts_enabled' => true]);
        $this->mockClaim();
        Http::fake(['www.googleapis.com/*' => Http::response([], 500), 'oauth2.googleapis.com/*' => Http::response([], 500)]);

        (new UploadVideoAnonsShortJob(-100111, 1067, 'leitana', $this->mediaPath, 'Заголовок'))->handle();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis.com'));
        Http::assertSent(fn (Request $request): bool => str_contains(($request['text'] ?? ''), 'Shorts НЕ опубликован')
            && str_contains(($request['text'] ?? ''), 'креды YouTube не настроены'));
    }

    public function test_shorts_job_refuses_with_missing_media(): void
    {
        config([
            'video-anons.yt_shorts_enabled' => true,
            'video-anons.yt.client_id' => 'cid',
            'video-anons.yt.client_secret' => 'secret',
            'video-anons.yt.refresh_token' => 'rt',
        ]);
        $this->mockClaim();
        Http::fake();

        (new UploadVideoAnonsShortJob(-100111, 1067, 'leitana', '/nonexistent/1067_video.mp4', 'Заголовок'))->handle();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis.com'));
        Http::assertSent(fn (Request $request): bool => str_contains(($request['text'] ?? ''), 'медиа-файл не найден'));
    }

    public function test_shorts_job_uploads_resumable_and_notifies(): void
    {
        config([
            'video-anons.yt_shorts_enabled' => true,
            'video-anons.yt.client_id' => 'cid',
            'video-anons.yt.client_secret' => 'secret',
            'video-anons.yt.refresh_token' => 'rt',
        ]);
        $this->mockClaim();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'YT-TOKEN']),
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/session/abc']),
            'www.googleapis.com/upload/session/*' => Http::response(['id' => 'vid123']),
        ]);

        (new UploadVideoAnonsShortJob(-100111, 1067, 'leitana', $this->mediaPath, 'Как звучит санскритская азбука'))->handle();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'upload/session/abc')
            && str_contains((string) $request->header('Content-Range')[0], 'bytes 0-')
            && $request->body() === 'fake-mp4-bytes');
        $session = null;
        Http::assertSent(function (Request $request) use (&$session): bool {
            if (str_contains($request->url(), 'uploadType=resumable')) {
                $session = $request;

                return true;
            }

            return false;
        });
        $this->assertNotNull($session, 'resumable-сессия не открыта');
        $this->assertSame('Как звучит санскритская азбука', $session['snippet']['title'] ?? null);
        $this->assertStringContainsString('/ga/vanons-yt-v1', (string) ($session['snippet']['description'] ?? ''));
        $this->assertSame('public', $session['status']['privacyStatus'] ?? null);
        Http::assertSent(fn (Request $request): bool => str_contains(($request['text'] ?? ''), 'youtube.com/shorts/vid123'));
    }

    public function test_shorts_job_truncates_title_to_100_chars(): void
    {
        config([
            'video-anons.yt_shorts_enabled' => true,
            'video-anons.yt.client_id' => 'cid',
            'video-anons.yt.client_secret' => 'secret',
            'video-anons.yt.refresh_token' => 'rt',
        ]);
        $this->mockClaim();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'YT-TOKEN']),
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/session/abc']),
            'www.googleapis.com/upload/session/*' => Http::response(['id' => 'vid123']),
        ]);
        $long = str_repeat('з', 150);

        (new UploadVideoAnonsShortJob(-100111, 1067, 'leitana', $this->mediaPath, $long))->handle();

        $session = null;
        Http::assertSent(function (Request $request) use (&$session): bool {
            if (str_contains($request->url(), 'uploadType=resumable')) {
                $session = $request;

                return true;
            }

            return false;
        });
        $this->assertNotNull($session, 'resumable-сессия не открыта');
        $this->assertSame(100, mb_strlen((string) ($session['snippet']['title'] ?? '')));
    }

    public function test_shorts_job_declined_upload_notifies_without_exception(): void
    {
        config([
            'video-anons.yt_shorts_enabled' => true,
            'video-anons.yt.client_id' => 'cid',
            'video-anons.yt.client_secret' => 'secret',
            'video-anons.yt.refresh_token' => 'rt',
        ]);
        $this->mockClaim();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'YT-TOKEN']),
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response(['error' => ['message' => 'quotaExceeded']], 403),
        ]);

        (new UploadVideoAnonsShortJob(-100111, 1067, 'leitana', $this->mediaPath, 'Заголовок'))->handle();

        Http::assertSent(fn (Request $request): bool => str_contains(($request['text'] ?? ''), 'Shorts НЕ опубликован')
            && str_contains(($request['text'] ?? ''), 'quotaExceeded'));
    }

    public function test_vk_job_refuses_with_empty_authorized_gate(): void
    {
        config([
            'video-anons.vk_wall_enabled' => true,
            'video-anons.vk.authorized_group_ids' => [],
            'video-anons.vk.target_group_id' => 88831040,
            'video-anons.vk.access_token' => 'VK-TOK',
        ]);
        $this->mockClaim();
        Http::fake(['api.vk.com/*' => Http::response(['response' => ['post_id' => 1]])]);

        (new PublishVideoAnonsVkWallJob(-100111, 1067, 'leitana', $this->mediaPath, 'Заголовок'))->handle();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.vk.com'));
        Http::assertSent(fn (Request $request): bool => str_contains(($request['text'] ?? ''), 'гейт авторизации'));
    }

    public function test_vk_job_refuses_target_not_authorized(): void
    {
        config([
            'video-anons.vk_wall_enabled' => true,
            'video-anons.vk.authorized_group_ids' => [111],
            'video-anons.vk.target_group_id' => 88831040,
            'video-anons.vk.access_token' => 'VK-TOK',
        ]);
        $this->mockClaim();
        Http::fake(['api.vk.com/*' => Http::response(['response' => ['post_id' => 1]])]);

        (new PublishVideoAnonsVkWallJob(-100111, 1067, 'leitana', $this->mediaPath, 'Заголовок'))->handle();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.vk.com'));
        Http::assertSent(fn (Request $request): bool => str_contains(($request['text'] ?? ''), 'не входит в авторизованный список'));
    }

    public function test_vk_job_without_headline_refuses(): void
    {
        config([
            'video-anons.vk_wall_enabled' => true,
            'video-anons.vk.authorized_group_ids' => [88831040],
            'video-anons.vk.target_group_id' => 88831040,
            'video-anons.vk.access_token' => 'VK-TOK',
        ]);
        $this->mockClaim();
        Http::fake(['api.vk.com/*' => Http::response(['response' => ['post_id' => 1]])]);

        (new PublishVideoAnonsVkWallJob(-100111, 1067, 'leitana', $this->mediaPath, '   '))->handle();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.vk.com'));
    }

    public function test_vk_job_posts_with_video_and_permalink(): void
    {
        config([
            'video-anons.vk_wall_enabled' => true,
            'video-anons.vk.authorized_group_ids' => [88831040],
            'video-anons.vk.target_group_id' => 88831040,
            'video-anons.vk.access_token' => 'VK-TOK',
        ]);
        $this->mockClaim();
        Http::fake([
            'api.vk.com/method/video.save' => Http::response(['response' => ['upload_url' => 'https://pus.vk.com/up?act=video', 'owner_id' => -88831040, 'video_id' => 42]]),
            'pus.vk.com/*' => Http::response('0'),
            'api.vk.com/method/wall.post' => Http::response(['response' => ['post_id' => 4567]]),
        ]);

        (new PublishVideoAnonsVkWallJob(-100111, 1067, 'leitana', $this->mediaPath, 'Как звучит санскритская азбука'))->handle();

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'method/wall.post')) {
                return false;
            }
            if ((int) $request['owner_id'] !== -88831040 || (int) $request['from_group'] !== 1) {
                return false;
            }
            $this->assertStringContainsString('Как звучит санскритская азбука', (string) $request['message']);
            $this->assertStringContainsString('/ga/vanons-vk-v1', (string) $request['message']);
            $this->assertSame('video-88831040_42', (string) $request['attachments']);

            return true;
        });
        Http::assertSent(fn (Request $request): bool => str_contains(($request['text'] ?? ''), 'vk.ru/wall-88831040_4567'));
    }

    public function test_vk_job_degrades_to_text_post_when_video_save_fails(): void
    {
        config([
            'video-anons.vk_wall_enabled' => true,
            'video-anons.vk.authorized_group_ids' => [88831040],
            'video-anons.vk.target_group_id' => 88831040,
            'video-anons.vk.access_token' => 'VK-TOK',
        ]);
        $this->mockClaim();
        Http::fake([
            'api.vk.com/method/video.save' => Http::response(['error' => ['error_code' => 15, 'error_msg' => 'Access denied']], 200),
            'api.vk.com/method/wall.post' => Http::response(['response' => ['post_id' => 4568]]),
        ]);

        (new PublishVideoAnonsVkWallJob(-100111, 1067, 'leitana', $this->mediaPath, 'Заголовок'))->handle();

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'method/wall.post')) {
                return false;
            }
            $this->assertArrayNotHasKey('attachments', $request->data());

            return true;
        });
        Http::assertSent(fn (Request $request): bool => str_contains(($request['text'] ?? ''), 'деградация в текст-пост'));
    }

    public function test_vk_job_declined_wall_post_notifies_without_exception(): void
    {
        config([
            'video-anons.vk_wall_enabled' => true,
            'video-anons.vk.authorized_group_ids' => [88831040],
            'video-anons.vk.target_group_id' => 88831040,
            'video-anons.vk.access_token' => 'VK-TOK',
        ]);
        $this->mockClaim();
        Http::fake([
            'api.vk.com/method/video.save' => Http::response(['response' => ['upload_url' => 'https://pus.vk.com/up', 'owner_id' => -88831040, 'video_id' => 42]]),
            'pus.vk.com/*' => Http::response('0'),
            'api.vk.com/method/wall.post' => Http::response(['error' => ['error_code' => 15, 'error_msg' => 'Access denied: wall post denied for token']], 200),
        ]);

        (new PublishVideoAnonsVkWallJob(-100111, 1067, 'leitana', $this->mediaPath, 'Заголовок'))->handle();

        Http::assertSent(fn (Request $request): bool => str_contains(($request['text'] ?? ''), 'wall.post отклонён')
            && str_contains(($request['text'] ?? ''), 'НЕ опубликован'));
    }

    public function test_vanons_surface_tracked_links_exist(): void
    {
        $yt = config('tracked_links.links.vanons-yt-v1');
        $vk = config('tracked_links.links.vanons-vk-v1');

        $this->assertSame('youtube_shorts', $yt['utm']['utm_source'] ?? null);
        $this->assertSame('video_anons_evergreen', $yt['utm']['utm_campaign'] ?? null);
        $this->assertSame('vk_wall', $vk['utm']['utm_source'] ?? null);
        $this->assertSame('video_anons_evergreen', $vk['utm']['utm_campaign'] ?? null);
    }
}
