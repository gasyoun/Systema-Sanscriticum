<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendLeadMagnet;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarketingSetting;
use App\Services\Messaging\DeliveryChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SendLeadMagnetJobTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    private string $tmpFile;

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::create([
            'tg_bot_username' => 'bot', 'tg_bot_token' => 'fake-tg',
            'vk_group_screen_name' => 'g', 'vk_access_token' => 'fake-vk',
            'max_bot_username' => 'mbot', 'max_bot_token' => 'fake-max',
        ]);

        // Перенаправляем public-диск на временную папку — Storage::fake на Windows
        // ведёт себя нестабильно с относительными путями fopen внутри Job.
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'magnet-test-'.uniqid();
        @mkdir($this->tmpDir.DIRECTORY_SEPARATOR.'magnets', 0777, true);
        config(['filesystems.disks.public.root' => $this->tmpDir]);
        $this->tmpFile = $this->tmpDir.DIRECTORY_SEPARATOR.'magnets'.DIRECTORY_SEPARATOR.'test.pdf';
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpDir) && is_dir($this->tmpDir)) {
            $this->rrmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        // Тут возможны Windows-блокировки от незакрытых fopen'ов — игнорируем ошибки удаления.
        $items = @scandir($dir) ?: [];
        foreach ($items as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir.DIRECTORY_SEPARATOR.$f;
            if (is_dir($p)) {
                $this->rrmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    private function makeLeadWithMagnet(string $channel, ?int $tgChatId = null, ?int $vkUserId = null, ?string $maxUserId = null): Lead
    {
        file_put_contents($this->tmpFile, 'PDF-CONTENT');

        $landing = LandingPage::create([
            'title' => 'Test', 'slug' => 'magnet-'.uniqid(),
            'lead_magnet_enabled' => true,
            'lead_magnet_file_path' => 'magnets/test.pdf',
            'lead_magnet_title' => 'Файл',
            'lead_magnet_caption' => 'Держи!',
        ]);

        return Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'Test', 'contact' => '+1', 'email' => 'a@b.c',
            'magnet_token' => 'TOKEN',
            'magnet_channel' => $channel,
            'telegram_chat_id' => $tgChatId,
            'vk_user_id' => $vkUserId,
            'max_user_id' => $maxUserId,
        ]);
    }

    /** @test */
    public function delivers_via_telegram_and_marks_delivered(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $lead = $this->makeLeadWithMagnet('telegram', tgChatId: 12345);

        (new SendLeadMagnet($lead->id))->handle(app(DeliveryChannelManager::class));

        $this->assertNotNull($lead->fresh()->magnet_delivered_at);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/sendDocument'));
    }

    /** @test */
    public function idempotent_does_not_resend_if_already_delivered(): void
    {
        Http::fake();

        $lead = $this->makeLeadWithMagnet('telegram', tgChatId: 1);
        $lead->update(['magnet_delivered_at' => now()->subHour()]);

        (new SendLeadMagnet($lead->id))->handle(app(DeliveryChannelManager::class));

        Http::assertNothingSent();
    }

    /** @test */
    public function skips_when_lead_does_not_exist(): void
    {
        Http::fake();
        (new SendLeadMagnet(99999))->handle(app(DeliveryChannelManager::class));
        Http::assertNothingSent();
    }

    /** @test */
    public function skips_when_landing_has_no_magnet(): void
    {
        Http::fake();

        $landing = LandingPage::create([
            'title' => 'L', 'slug' => 'l-'.uniqid(),
            'lead_magnet_enabled' => false,
        ]);
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
            'magnet_token' => 'T', 'magnet_channel' => 'telegram',
            'telegram_chat_id' => 1,
        ]);

        (new SendLeadMagnet($lead->id))->handle(app(DeliveryChannelManager::class));

        Http::assertNothingSent();
        $this->assertNull($lead->fresh()->magnet_delivered_at);
    }

    /** @test */
    public function skips_when_user_id_missing_in_channel(): void
    {
        Http::fake();
        // Magnet channel = telegram, но telegram_chat_id отсутствует —
        // юзер ещё не запустил бота. Job не должен попытаться отправить.
        $lead = $this->makeLeadWithMagnet('telegram', tgChatId: null);

        (new SendLeadMagnet($lead->id))->handle(app(DeliveryChannelManager::class));

        Http::assertNothingSent();
    }

    /** @test */
    public function delivers_via_actual_channel_overriding_stale_magnet_channel(): void
    {
        // Сценарий Lead #179: при сабмите зафиксировали magnet_channel='telegram'
        // (telegram_chat_id так и остался null), но юзер пришёл в VK — VK-вебхук
        // записал vk_user_id и задиспатчил с channel='vk'. Доставляем в VK,
        // а не падаем в «no user_id in telegram».
        Http::fake([
            'api.vk.com/method/docs.getMessagesUploadServer*' => Http::response([
                'response' => ['upload_url' => 'https://upload.example/vk'],
            ]),
            'upload.example/vk' => Http::response(['file' => 'fake-file-token']),
            'api.vk.com/method/docs.save*' => Http::response([
                'response' => ['doc' => ['id' => 111, 'owner_id' => -222]],
            ]),
            'api.vk.com/method/messages.send*' => Http::response(['response' => 1]),
        ]);

        $lead = $this->makeLeadWithMagnet('telegram', tgChatId: null, vkUserId: 555);

        (new SendLeadMagnet($lead->id, 'vk'))->handle(app(DeliveryChannelManager::class));

        $fresh = $lead->fresh();
        $this->assertNotNull($fresh->magnet_delivered_at);
        // magnet_channel перепривязан к фактическому каналу доставки.
        $this->assertSame('vk', $fresh->magnet_channel);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'messages.send'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
    }

    /** @test */
    public function skips_when_file_missing_on_disk(): void
    {
        Http::fake();
        $landing = LandingPage::create([
            'title' => 'L', 'slug' => 'l-'.uniqid(),
            'lead_magnet_enabled' => true,
            'lead_magnet_file_path' => 'magnets/nope.pdf',
        ]);
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
            'magnet_token' => 'T', 'magnet_channel' => 'telegram',
            'telegram_chat_id' => 1,
        ]);

        (new SendLeadMagnet($lead->id))->handle(app(DeliveryChannelManager::class));

        Http::assertNothingSent();
        $this->assertNull($lead->fresh()->magnet_delivered_at);
    }

    /** @test */
    public function vk_send_uses_4_step_api(): void
    {
        Http::fake([
            'api.vk.com/method/docs.getMessagesUploadServer*' => Http::response([
                'response' => ['upload_url' => 'https://upload.example/vk'],
            ]),
            'upload.example/vk' => Http::response(['file' => 'fake-file-token']),
            'api.vk.com/method/docs.save*' => Http::response([
                'response' => ['doc' => ['id' => 111, 'owner_id' => -222]],
            ]),
            'api.vk.com/method/messages.send*' => Http::response(['response' => 1]),
        ]);

        $lead = $this->makeLeadWithMagnet('vk', vkUserId: 555);

        (new SendLeadMagnet($lead->id))->handle(app(DeliveryChannelManager::class));

        $this->assertNotNull($lead->fresh()->magnet_delivered_at);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'docs.getMessagesUploadServer'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'docs.save'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'messages.send'));
    }

    /** @test */
    public function max_send_uploads_then_sends(): void
    {
        // Closure-фейк ловит любой URL — устойчивее к pattern-расхождениям, чем массив паттернов.
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/uploads')) {
                return Http::response(['token' => 'max-upload-token']);
            }
            if (str_contains($request->url(), '/messages')) {
                return Http::response(['message_id' => 1]);
            }

            return Http::response([], 404);
        });

        $lead = $this->makeLeadWithMagnet('max', maxUserId: '9999');

        (new SendLeadMagnet($lead->id))->handle(app(DeliveryChannelManager::class));

        $this->assertNotNull($lead->fresh()->magnet_delivered_at);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'botapi.max.ru/uploads'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'botapi.max.ru/messages'));
    }

    /** @test */
    public function uses_default_caption_when_lead_magnet_caption_empty(): void
    {
        // Читаем body внутри fake-callback: к моменту Http::assertSent внешний
        // fopen-handle уже закрыт (try/finally fclose в TelegramDeliveryChannel),
        // и доступ к $req->body() через assertSent даёт TypeError на закрытом потоке.
        $captionFound = false;
        $endpointHit = false;
        Http::fake(function ($request) use (&$captionFound, &$endpointHit) {
            if (str_contains($request->url(), '/sendDocument')) {
                $endpointHit = true;
                if (str_contains($request->body(), 'Алфавит')) {
                    $captionFound = true;
                }
            }

            return Http::response(['ok' => true]);
        });

        file_put_contents($this->tmpFile, 'PDF');
        $landing = LandingPage::create([
            'title' => 'L', 'slug' => 'l-'.uniqid(),
            'lead_magnet_enabled' => true,
            'lead_magnet_file_path' => 'magnets/test.pdf',
            'lead_magnet_title' => 'Алфавит',
            'lead_magnet_caption' => null,
        ]);
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
            'magnet_token' => 'T', 'magnet_channel' => 'telegram',
            'telegram_chat_id' => 1,
        ]);

        (new SendLeadMagnet($lead->id))->handle(app(DeliveryChannelManager::class));

        $this->assertTrue($endpointHit, '/sendDocument endpoint не вызван');
        $this->assertTrue($captionFound, 'caption "Алфавит" не найден в multipart body');
    }
}
