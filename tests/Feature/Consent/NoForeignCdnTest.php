<?php

declare(strict_types=1);

namespace Tests\Feature\Consent;

use App\Support\VideoEmbed;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 152-ФЗ: шрифты, иконки, Alpine и превью видео — со своего сервера.
 * Зарубежные CDN (Google Fonts, cdnjs, jsDelivr, img.youtube.com) получали IP
 * каждого посетителя ещё до любого согласия. Страж по исходникам шаблонов.
 */
class NoForeignCdnTest extends TestCase
{
    public function test_no_view_loads_fonts_icons_or_alpine_from_foreign_cdn(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            $path = $file->getRelativePathname();
            if (str_starts_with($path, 'vendor')) {
                continue; // сторонние пакеты (filament-tiptap) — вне нашей вёрстки
            }

            $this->assertDoesNotMatchRegularExpression(
                '~https://(fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|img\.youtube\.com)~',
                $file->getContents(),
                "{$path}: ресурс с зарубежного CDN",
            );
        }
    }

    /**
     * H6533: CSS-слой — второе слепое пятно. @import в resources/css/article.css
     * тянул fonts.googleapis.com до любого согласия, а страж выше сканировал
     * только views/. Теперь исходники CSS под тем же запретом хостов.
     */
    public function test_no_css_source_loads_foreign_cdn(): void
    {
        foreach (File::allFiles(resource_path('css')) as $file) {
            $path = $file->getRelativePathname();

            $this->assertDoesNotMatchRegularExpression(
                '~https://(fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|img\.youtube\.com)~',
                $file->getContents(),
                "{$path}: ресурс с зарубежного CDN",
            );
        }
    }

    /**
     * H6533: прод-сборка (public/build, gitignored — сканируется когда собрана,
     * например на деплое) не должна содержать зарубежных CDN ни в манифесте,
     * ни в собранных css-ассетах.
     */
    public function test_built_css_assets_and_manifest_load_no_foreign_cdn(): void
    {
        $build = public_path('build');

        if (! is_dir($build)) {
            $this->addToAssertionCount(1); // сборки нет локально — не красним

            return;
        }

        foreach (File::allFiles($build) as $file) {
            if (! in_array($file->getExtension(), ['css', 'json'], true)) {
                continue;
            }

            $path = 'build/'.$file->getRelativePathname();

            $this->assertDoesNotMatchRegularExpression(
                '~https://(fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|img\.youtube\.com)~',
                $file->getContents(),
                "{$path}: собранный ассет с зарубежного CDN",
            );
        }
    }

    public function test_youtube_poster_goes_through_own_server_and_embed_is_nocookie(): void
    {
        $url = 'https://www.youtube.com/watch?v=FmdnLXZ4UFo';

        $this->assertSame('/video-thumb/FmdnLXZ4UFo.jpg', VideoEmbed::poster($url));
        $this->assertStringEndsWith('/video-thumb/FmdnLXZ4UFo.jpg', route('video.thumb', ['id' => 'FmdnLXZ4UFo']));
        $this->assertStringStartsWith('https://www.youtube-nocookie.com/embed/FmdnLXZ4UFo', (string) VideoEmbed::embed($url));
    }

    public function test_thumb_proxy_fetches_once_then_serves_from_cache(): void
    {
        Storage::fake('public');
        Http::fake(['img.youtube.com/*' => Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg'])]);

        $this->get('/video-thumb/FmdnLXZ4UFo.jpg')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/video-thumb/FmdnLXZ4UFo.jpg')->assertOk();

        Http::assertSentCount(1);
        Storage::disk('public')->assertExists('video-thumbs/FmdnLXZ4UFo.jpg');
    }

    public function test_thumb_proxy_rejects_bad_ids_and_failed_fetches(): void
    {
        Storage::fake('public');
        Http::fake(['img.youtube.com/*' => Http::response('not found', 404)]);

        $this->get('/video-thumb/short.jpg')->assertNotFound();
        $this->get('/video-thumb/AAAAAAAAAAA.jpg')->assertNotFound();
        Storage::disk('public')->assertMissing('video-thumbs/AAAAAAAAAAA.jpg');
    }
}
