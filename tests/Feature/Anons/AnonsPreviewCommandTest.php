<?php

declare(strict_types=1);

namespace Tests\Feature\Anons;

use App\Models\AnonsDestinationRun;
use App\Models\AnonsPublication;
use App\Services\Anons\Adapters\AdapterRegistry;
use App\Services\Anons\AnonsPublishingService;
use App\Services\Anons\CtaPlaqueCompositor;
use App\Services\Anons\PlaqueInspector;
use App\Services\Anons\SessionHealthProbe;
use App\Services\Stories\StoryPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Anons\Support\RecordingFakeAdapter;
use Tests\TestCase;

/**
 * H5049 R3 (verifier gap 2): anons:preview — рендерит каждый кадр ровно
 * как получит адаптер: артефакт существует, план несёт измеренный
 * прямоугольник плашки, медиа-зону, безопасные зоны и валидную подпись;
 * НИЧЕГО не публикует.
 */
class AnonsPreviewCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $assetDir;

    private string $asset;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram_story.subprocess_lane' => false]);

        $dir = storage_path('app/testing/h5049/'.bin2hex(random_bytes(4)));
        @mkdir($dir, 0775, true);
        $this->assetDir = $dir;
        $this->asset = $dir.'/base.jpg';
        $img = imagecreatetruecolor(1080, 1920);
        imagefilledrectangle($img, 0, 0, 1079, 1919, imagecolorallocate($img, 214, 226, 236));
        imagejpeg($img, $this->asset, 90);
        imagedestroy($img);

        $registry = new AdapterRegistry;
        $registry->register(new RecordingFakeAdapter);
        $this->app->instance(AnonsPublishingService::class, new AnonsPublishingService(
            $registry, new CtaPlaqueCompositor, new PlaqueInspector,
            new SessionHealthProbe, app(StoryPublisher::class),
        ));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->assetDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->assetDir);
        parent::tearDown();
    }

    private function manifestPath(): string
    {
        $path = $this->assetDir.'/manifest.json';
        file_put_contents($path, (string) json_encode([
            'version' => 1,
            'campaign' => 'm26',
            'creative' => 'preview-test',
            'slot' => '2026-09-18T0900',
            'frames' => [[
                'asset' => $this->asset,
                'caption' => 'Набор осенней группы с нуля, онлайн.',
                'alt_text' => 'Анонс осенней группы',
                'cta_text' => 'страница записи',
                'cta_url' => 'https://samskrte.ru/ga/m26-rs-st-preview-20260918-01',
            ]],
            'destinations' => [['platform' => 'telegram_story', 'account' => 'rusamskrtam']],
        ], JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /** @test */
    public function preview_renders_full_plan_without_publishing(): void
    {
        $exit = Artisan::call('anons:preview', ['manifest' => $this->manifestPath(), '--json' => true]);
        $this->assertSame(0, $exit);

        $plan = (string) Artisan::output();
        // Pretty-print JSON печатается блоком с "{\n" — инлайн-объекты
        // (plaque_rect_px: {"x":…}) в одну строку не считаются.
        $jsonStart = strpos($plan, "\n{\n");
        $this->assertNotFalse($jsonStart, 'Preview must emit a JSON plan.');
        $decoded = json_decode(substr($plan, (int) $jsonStart), true);
        $this->assertIsArray($decoded);

        $frame = $decoded['frames'][0];
        $this->assertFileExists($frame['artifact'], 'Rendered artifact must exist on disk.');

        // Пиксельная приёмка артефакта превью (та же PlaqueInspector).
        $problems = (new PlaqueInspector)->inspect($frame['artifact'], $frame['plaque_rect_px']);
        $this->assertSame([], $problems, implode('; ', $problems));

        // Геометрия: медиа-зона из измеренной плашки (left=11 %, top=55 %).
        $this->assertEqualsWithDelta(11.0, $frame['media_area']['x'], 0.5);
        $this->assertEqualsWithDelta(55.0, $frame['media_area']['y'], 0.5);
        $this->assertEqualsWithDelta(78.0, $frame['media_area']['w'], 0.5);
        $this->assertEqualsWithDelta(14.0, $frame['media_area']['h'], 0.5);
        $this->assertEqualsWithDelta(8.0, $frame['safe_zones']['top_pct'], 0.01);
        $this->assertEqualsWithDelta(8.0, $frame['safe_zones']['bottom_pct'], 0.01);

        // Подпись: URL не первым элементом; короткая ссылка чистая.
        $this->assertStringStartsNotWith('http', $frame['caption']);
        $this->assertSame('https://samskrte.ru/ga/m26-rs-st-preview-20260918-01', $frame['short_link']);

        // НИЧЕГО не опубликовано: ни адаптер-вызовов, ни строк публикаций.
        $this->assertSame(0, AnonsPublication::query()->count());
        $this->assertSame(0, AnonsDestinationRun::query()->count());
    }
}
