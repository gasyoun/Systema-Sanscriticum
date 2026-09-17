<?php

declare(strict_types=1);

namespace Tests\Feature\Anons;

use App\Services\Anons\CtaPlaqueCompositor;
use App\Services\Anons\PlaqueInspector;
use App\Services\Anons\PublicationKey;
use App\Services\Anons\PublicationManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H5049 R1/R4/R5: манифест fail-closed, плашка физически в пикселях,
 * приёмочный контроль ловит ссылку без отрисованного слоя (регрессия H5049).
 */
class ManifestAndCompositorTest extends TestCase
{
    use RefreshDatabase;

    private string $asset;

    private string $assetDir;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = storage_path('app/testing/h5049/'.bin2hex(random_bytes(4)));
        @mkdir($dir, 0775, true);
        $this->assetDir = $dir;
        $this->asset = $dir.'/story-base.jpg';
        // Реальная фотография-заглушка: 1080x1920, светлое фото (не тёмное).
        $img = imagecreatetruecolor(1080, 1920);
        imagefilledrectangle($img, 0, 0, 1079, 1919, imagecolorallocate($img, 220, 210, 200));
        imagejpeg($img, $this->asset, 90);
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->assetDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->assetDir);
        parent::tearDown();
    }

    private function manifestData(array $overrides = []): array
    {
        return array_merge([
            'version' => 1,
            'campaign' => 'm26',
            'creative' => 'sep-start',
            'slot' => '2026-09-18T08:00',
            'frames' => [[
                'asset' => $this->asset,
                'caption' => 'Набор осенней группы: грамматика санскрита с нуля.',
                'alt_text' => 'Анонс осенней группы',
                'cta_text' => 'страница записи',
                'cta_url' => 'https://samskrte.ru/ga/m26-rusamskrtam-st-sep-20260918-01',
            ]],
            'destinations' => [
                ['platform' => 'telegram_story', 'account' => 'rusamskrtam'],
            ],
            'deletion_policy' => 'retain',
        ], $overrides);
    }

    /** @test */
    public function valid_manifest_passes_and_hash_is_stable(): void
    {
        $m = PublicationManifest::fromArray($this->manifestData());
        $this->assertSame([], $m->validate());

        $key1 = PublicationKey::fromManifest($m);
        $key2 = PublicationKey::fromManifest(PublicationManifest::fromArray($this->manifestData()));
        $this->assertSame($key1, $key2, 'Same manifest → same publication key (idempotency basis R2).');

        // Ключ ЧУВСТВИТЕЛЕН к slot и destination.
        $otherSlot = PublicationManifest::fromArray($this->manifestData(['slot' => '2026-09-19T08:00']));
        $this->assertNotSame($key1, PublicationKey::fromManifest($otherSlot));
    }

    /** @test */
    public function validator_rejects_ambiguous_input(): void
    {
        // Неизвестная версия.
        $this->assertNotSame([], PublicationManifest::fromArray($this->manifestData(['version' => 2]))->validate());

        // Относительный путь ассета — двусмысленность, отклоняем.
        $bad = $this->manifestData();
        $bad['frames'][0]['asset'] = 'storage/story.jpg';
        $this->assertNotSame([], PublicationManifest::fromArray($bad)->validate());

        // UTM прямо в cta_url — запрещено (чистая короткая ссылка).
        $bad = $this->manifestData();
        $bad['frames'][0]['cta_url'] = 'https://samskrte.ru/ga/x?utm_source=t';
        $this->assertNotSame([], PublicationManifest::fromArray($bad)->validate());

        // URL первым элементом подписи — нарушение anons-правила.
        $bad = $this->manifestData();
        $bad['frames'][0]['caption'] = 'https://samskrte.ru/ga/x сначала ссылка';
        $this->assertNotSame([], PublicationManifest::fromArray($bad)->validate());

        // Дубликат адресата.
        $bad = $this->manifestData();
        $bad['destinations'] = [
            ['platform' => 'telegram_story', 'account' => 'rusamskrtam'],
            ['platform' => 'telegram_story', 'account' => 'rusamskrtam'],
        ];
        $this->assertNotSame([], PublicationManifest::fromArray($bad)->validate());

        // test_mode без test_destination — fail-closed.
        $bad = $this->manifestData(['test_mode' => true]);
        $this->assertNotSame([], PublicationManifest::fromArray($bad)->validate());

        // Несуществующий файл.
        $bad = $this->manifestData();
        $bad['frames'][0]['asset'] = '/nonexistent/asset.jpg';
        $this->assertNotSame([], PublicationManifest::fromArray($bad)->validate());
    }

    /** @test */
    public function test_mode_redirects_to_private_test_destination_only(): void
    {
        $m = PublicationManifest::fromArray($this->manifestData([
            'test_mode' => true,
            'test_destination' => ['platform' => 'telegram_story', 'account' => 'marcis_test'],
        ]));

        $destinations = $m->effectiveDestinations();
        $this->assertCount(1, $destinations);
        $this->assertSame('marcis_test', $destinations[0]['account']);
        $accounts = array_column($destinations, 'account');
        $this->assertNotContains('rusamskrtam', $accounts, 'production destination must not leak into test mode');
    }

    /** @test */
    public function compositor_burns_plaque_into_pixels_and_inspector_accepts(): void
    {
        $compositor = new CtaPlaqueCompositor;
        $out = $this->assetDir.'/plaqued.jpg';

        $compositor->renderPlaque($this->asset, ['cta_text' => 'страница записи'], $out);

        $this->assertFileExists($out);
        $this->assertNotSame(hash_file('sha256', $this->asset), hash_file('sha256', $out), 'Rendered artifact must differ from the source (plaque burned in).');

        $rect = $compositor->pixelRect(1080, 1920);
        $inspector = new PlaqueInspector;
        $problems = $inspector->inspect($out, $rect);

        $this->assertSame([], $problems, 'Plaqued artifact must pass pixel inspection: '.implode('; ', $problems));

        // Медиа-зона из измеренной плашки: left-edge семантика Telegram
        // (x = левая кромка в %), центр-X по layout-константе.
        $mediaArea = $compositor->bounds->asMediaAreaCoordinates();
        $expectedLeft = CtaPlaqueCompositor::RECT['x'] - CtaPlaqueCompositor::RECT['w'] / 2.0; // 50 − 39 = 11
        $this->assertEqualsWithDelta($expectedLeft, $mediaArea['x'], 0.5);
        $this->assertEqualsWithDelta(CtaPlaqueCompositor::RECT['y'], $mediaArea['y'], 0.5);
        $this->assertEqualsWithDelta(CtaPlaqueCompositor::RECT['w'], $mediaArea['w'], 0.5);
        $this->assertEqualsWithDelta(CtaPlaqueCompositor::RECT['h'], $mediaArea['h'], 0.5);

        @unlink($out);
    }

    /** @test */
    public function inspector_fails_when_media_area_exists_but_plaque_layer_absent(): void
    {
        // Регрессия H5049: mediaAreaUrl есть, отрисованной плашки нет.
        $inspector = new PlaqueInspector;
        $rect = (new CtaPlaqueCompositor)->pixelRect(1080, 1920);

        $problems = $inspector->inspect($this->asset, $rect);

        $this->assertNotSame([], $problems, 'A mediaAreaUrl without a rendered plaque MUST fail inspection.');
        $this->assertStringContainsString('plaque layer ABSENT', implode(' ', $problems));
    }
}
