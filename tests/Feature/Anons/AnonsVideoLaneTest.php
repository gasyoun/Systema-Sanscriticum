<?php

declare(strict_types=1);

namespace Tests\Feature\Anons;

use App\Models\AnonsPublication;
use App\Models\MarketingSetting;
use App\Services\Anons\Adapters\AdapterRegistry;
use App\Services\Anons\AnonsPublishingService;
use App\Services\Anons\CtaPlaqueCompositor;
use App\Services\Anons\PlaqueInspector;
use App\Services\Anons\PublicationManifest;
use App\Services\Anons\SessionHealthProbe;
use App\Services\Stories\StoryPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H6915: видео-лейн канальных постов — kind=video + media_path в
 * манифесте, sendVideo в TelegramPostAdapter (лимит 50 МБ, caption +
 * parse_mode=HTML), идемпотентность по publication_key (повторный
 * прогон не создаёт второй пост).
 */
class AnonsVideoLaneTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $video;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram_story.subprocess_lane' => false]);

        $this->dir = storage_path('app/testing/h6915/'.bin2hex(random_bytes(4)));
        @mkdir($this->dir, 0775, true);

        // Фейковый «видео-фрагмент 1:14» — валидный mp4-контейнер не нужен,
        // Bot API фейкается; важен размер и читаемость.
        $this->video = $this->dir.'/course-fragment.mp4';
        file_put_contents($this->video, str_repeat('V', 2048));

        MarketingSetting::create(['tg_bot_token' => 'TESTMAGNETTOKEN']);
        MarketingSetting::flushCached();
        config(['services.telegram_story.channel_chat_id' => '@samskrte']);

        Http::fake(function (Request $request) {
            return str_contains($request->url(), '/getChat')
                ? Http::response(['ok' => true, 'result' => ['id' => -100123]])
                : Http::response(['ok' => true, 'result' => ['message_id' => 647]]);
        });
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function service(): AnonsPublishingService
    {
        return new AnonsPublishingService(
            new AdapterRegistry,
            new CtaPlaqueCompositor,
            new PlaqueInspector,
            new SessionHealthProbe,
            app(StoryPublisher::class),
        );
    }

    private function videoManifest(array $overrides = []): PublicationManifest
    {
        $frame = array_merge([
            'kind' => PublicationManifest::KIND_VIDEO,
            'media_path' => $this->video,
            'caption' => 'Фрагмент занятия: разбор деванагари.',
            'alt_text' => 'Видео-фрагмент урока санскрита',
            'cta_url' => 'https://samskrte.ru/ga/m26-samskrte-st-video-20261020-1',
        ], $overrides['frame'] ?? []);
        unset($overrides['frame']);

        return PublicationManifest::fromArray(array_merge([
            'version' => 1,
            'campaign' => 'm26',
            'creative' => 'video-fragment',
            'slot' => '2026-10-20T11:00',
            'frames' => [$frame],
            'destinations' => [['platform' => 'telegram_post', 'account' => 'samskrte']],
        ], $overrides));
    }

    /** @test */
    public function video_frame_validates_and_skips_cta_plaque_requirement(): void
    {
        $this->assertSame([], $this->videoManifest()->validate());
    }

    /** @test */
    public function video_frame_without_media_path_fails_validation(): void
    {
        $errors = $this->videoManifest(['frame' => ['media_path' => null]])->validate();

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('media_path is required', implode(' ', $errors));
    }

    /** @test */
    public function video_frame_above_50mb_fails_validation_before_any_publish(): void
    {
        $big = $this->dir.'/too-big.mp4';
        $h = fopen($big, 'wb');
        assert(is_resource($h));
        ftruncate($h, 51 * 1024 * 1024);
        fclose($h);

        try {
            $errors = $this->videoManifest(['frame' => ['media_path' => $big]])->validate();

            $this->assertNotSame([], $errors);
            $this->assertStringContainsString('50MB', implode(' ', $errors));
        } finally {
            @unlink($big);
        }
    }

    /** @test */
    public function telegram_post_adapter_sends_video_with_caption_and_html(): void
    {
        $adapter = (new AdapterRegistry)->for('telegram_post');

        $result = $adapter->publishFrame([
            'kind' => 'video',
            'asset_path' => $this->video,
            'caption' => 'Фрагмент занятия',
            'link' => 'https://samskrte.ru/ga/m26-x',
        ]);

        $this->assertSame('647', $result['id']);
        $send = $this->sendVideoRequest();
        $this->assertNotNull($send, 'sendVideo call expected');
        $this->assertTrue(str_contains($send[0], 'botTESTMAGNETTOKEN'), 'magnet bot credential');
        $this->assertStringContainsString('parse_mode', $send[1]);
        $this->assertStringContainsString('HTML', $send[1]);
    }

    /** recorded() отдаёт list<[Request, Response]> — [url, body] первого sendVideo. */
    private function sendVideoRequest(): ?array
    {
        foreach (Http::recorded() as $entry) {
            $request = is_array($entry) ? $entry[0] : $entry;
            if (str_contains($request->url(), '/sendVideo')) {
                return [$request->url(), (string) $request->body()];
            }
        }

        return null;
    }

    /** @test */
    public function adapter_refuses_video_above_50mb_with_clear_error(): void
    {
        $big = $this->dir.'/too-big.mp4';
        $h = fopen($big, 'wb');
        assert(is_resource($h));
        ftruncate($h, 51 * 1024 * 1024);
        fclose($h);

        try {
            $adapter = (new AdapterRegistry)->for('telegram_post');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('50MB');
            $adapter->publishFrame(['kind' => 'video', 'asset_path' => $big, 'caption' => 'x']);
        } finally {
            @unlink($big);
        }
    }

    /** @test */
    public function publishing_video_manifest_is_idempotent_no_second_post_on_rerun(): void
    {
        $service = $this->service();
        $manifest = $this->videoManifest();

        $first = $service->publish($manifest);
        $this->assertSame(
            AnonsPublication::STATUS_PUBLISHED,
            $first->status,
            'run last_error: '.var_export($first->runs()->value('last_error'), true)
        );
        $sentAfterFirst = $this->sendVideoCount();

        $second = $service->publish($manifest);
        $this->assertSame($first->id, $second->id, 'same publication_key resumes the SAME publication row');
        $this->assertSame($sentAfterFirst, $this->sendVideoCount(), 're-run must not create a second video post');
        $this->assertSame(1, $sentAfterFirst);
    }

    /** Версионно-нейтральный счётчик sendVideo-вызовов (recorded() отдаёт
     * list<[Request, Response]> в этом фреймворке). */
    private function sendVideoCount(): int
    {
        $count = 0;
        foreach (Http::recorded() as $entry) {
            $request = is_array($entry) ? $entry[0] : $entry;
            if (str_contains($request->url(), '/sendVideo')) {
                $count++;
            }
        }

        return $count;
    }
}
