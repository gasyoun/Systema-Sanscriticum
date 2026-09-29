<?php

declare(strict_types=1);

namespace Tests\Feature\Anons;

use App\Http\Controllers\Api\AnonsApiController;
use App\Models\AnonsArchiveItem;
use App\Models\AnonsLinkClick;
use App\Models\User;
use App\Services\Anons\Adapters\AdapterRegistry;
use App\Services\Anons\AnonsArchiveIndexer;
use App\Services\Anons\AnonsPublishingService;
use App\Services\Anons\CtaPlaqueCompositor;
use App\Services\Anons\PlaqueInspector;
use App\Services\Anons\SessionHealthProbe;
use App\Services\Stories\StoryPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Anons\Support\RecordingFakeAdapter;
use Tests\TestCase;

/**
 * H5049 R10/R6/R11: аутентифицированный HTTP API поверх ЕДИНГОГО сервиса,
 * счётчик /ga/-кликов, поиск в архивном каталоге.
 */
class AnonsApiAndOpsTest extends TestCase
{
    use RefreshDatabase;

    private string $asset;

    private string $assetDir;

    private RecordingFakeAdapter $story;

    private AdapterRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram_story.subprocess_lane' => false]);

        $dir = storage_path('app/testing/h5049/'.bin2hex(random_bytes(4)));
        @mkdir($dir, 0775, true);
        $this->assetDir = $dir;
        $this->asset = $dir.'/api-base.jpg';
        $img = imagecreatetruecolor(1080, 1920);
        imagefilledrectangle($img, 0, 0, 1079, 1919, imagecolorallocate($img, 210, 220, 230));
        imagejpeg($img, $this->asset, 90);
        imagedestroy($img);

        $this->story = new RecordingFakeAdapter;
        $this->registry = new AdapterRegistry;
        $this->registry->register($this->story);

        $service = new AnonsPublishingService(
            $this->registry, new CtaPlaqueCompositor, new PlaqueInspector,
            new SessionHealthProbe, app(StoryPublisher::class),
        );
        $this->app->instance(AnonsPublishingService::class, $service);
        $this->app->when(AnonsApiController::class)
            ->needs(AnonsPublishingService::class)
            ->give(fn () => $service);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->assetDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->assetDir);
        parent::tearDown();
    }

    private function manifestData(): array
    {
        return [
            'version' => 1,
            'campaign' => 'm26',
            'creative' => 'api-frame',
            'slot' => '2026-09-18T09:00',
            'frames' => [[
                'asset' => $this->asset,
                'caption' => 'API-кадр: набор осенней группы.',
                'alt_text' => 'API анонс',
                'cta_text' => 'запись',
                'cta_url' => 'https://samskrte.ru/ga/m26-rusamskrtam-st-api-20260918-01',
            ]],
            'destinations' => [['platform' => 'telegram_story', 'account' => 'rusamskrtam']],
        ];
    }

    /** @test */
    public function api_requires_sanctum_token(): void
    {
        $this->postJson('/api/anons', ['manifest' => $this->manifestData()])->assertUnauthorized();
    }

    /** @test */
    public function api_draft_validates_and_publish_is_idempotent(): void
    {
        Sanctum::actingAs(User::factory()->create());

        // draft: валиден.
        $this->postJson('/api/anons', ['manifest' => $this->manifestData()])
            ->assertOk()
            ->assertJson(['valid' => true]);

        // Невалидный манифест → 422 со списком ошибок.
        $bad = $this->manifestData();
        $bad['frames'][0]['cta_text'] = '';
        $this->postJson('/api/anons', ['manifest' => $bad])->assertOk()->assertJson(['valid' => false]);

        // publish: идемпотентен (два вызова — одна публикация).
        $p1 = $this->postJson('/api/anons/publish', ['manifest' => $this->manifestData()])->assertOk()->json();
        $p2 = $this->postJson('/api/anons/publish', ['manifest' => $this->manifestData()])->assertOk()->json();
        $this->assertSame($p1['publication_key'], $p2['publication_key']);
        $this->assertSame('published', $p2['status']);
        $this->assertSame(1, count($this->story->published), 'API rerun must not duplicate.');

        // status по ключу.
        $this->getJson('/api/anons/'.$p1['publication_key'])->assertOk()->assertJsonStructure(['status', 'runs']);

        // rollback: deletion_policy=retain → отказ.
        $this->postJson('/api/anons/'.$p1['publication_key'].'/rollback')->assertStatus(422);
    }

    /** @test */
    public function ga_clicks_are_recorded_without_pii(): void
    {
        config([
            'tracked_links.story_campaigns' => ['m26' => ['destination' => 'https://samskrte.ru/kursy', 'utm_campaign' => 'm26', 'utm_term' => '']],
            'tracked_links.story_accounts' => ['rusamskrtam' => 'rusamskrtam_story'],
        ]);

        $this->withoutExceptionHandling();
        // Слаг в формате anons-сториз → 302 на destination.
        $this->get('/ga/m26-rusamskrtam-st-sep-20260918-01')->assertRedirect('https://samskrte.ru/kursy');

        $click = AnonsLinkClick::query()->latest('id')->first();
        $this->assertNotNull($click, '/ga/ click must be recorded (H5049 R6: redirect previously never counted).');
        $this->assertSame('m26-rusamskrtam-st-sep-20260918-01', $click->link);
        $this->assertSame('story', $click->utm['utm_medium']);
    }

    /** @test */
    public function archive_search_finds_evergreen_assets_without_rescanning(): void
    {
        AnonsArchiveItem::query()->create([
            'platform' => 'telegram_story', 'account' => 'rusamskrtam', 'remote_id' => '7',
            'text' => 'Осенний набор: грамматика санскрита с нуля, онлайн-группа',
            'tags' => ['анонс', 'осень'],
            'expires_at' => now()->addDays(3),
            'media_hash' => hash('sha256', 'old-bytes'),
        ]);
        AnonsArchiveItem::query()->create([
            'platform' => 'telegram_story', 'account' => 'rusamskrtam', 'remote_id' => '8',
            'text' => 'Устаревший ценник 5000 руб.',
            'expires_at' => now()->subDays(1), // истёкшая — текущностный фильтр отбрасывает
        ]);

        $indexer = new AnonsArchiveIndexer(new SessionHealthProbe);

        $hits = $indexer->search('набор', currentOnly: true);
        $this->assertCount(1, $hits, 'Expired stories must be filtered out (currentness).');
        $this->assertSame('7', $hits[0]->remote_id);

        $byHash = $indexer->search(hash('sha256', 'old-bytes'), currentOnly: false);
        $this->assertCount(1, $byHash);
    }
}
