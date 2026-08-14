<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * H1463 — /transliterate flag gate + playground markup.
 * H2763 — public /sanskritorium wrapper (indexable, flag-independent).
 */
class TransliteratePlaygroundTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_off_route_404s(): void
    {
        config(['features.hub_transliterate' => false]);

        $this->get('/transliterate')->assertNotFound();
    }

    public function test_flag_on_renders_playground_and_vite_handle(): void
    {
        config(['features.hub_transliterate' => true]);

        $res = $this->get('/transliterate')->assertOk();
        $res->assertSee('hub-transliterate-input', false);
        $res->assertSee('hub-result-deva', false);
        $res->assertSee('hub-result-iast', false);
        $res->assertSee('hub-result-slp1', false);
        $res->assertSee('hub-diacritics', false);
        // @vite is stripped by TestCase::withoutVite(); assert playground
        // wiring + copy instead of the asset filename.
        $res->assertSee('Транслитерация', false);
        $res->assertSee('sanskrit-util', false);
        $res->assertSee('name="robots" content="noindex, follow"', false);
    }

    public function test_sanskritorium_is_public_and_indexable_when_flag_off(): void
    {
        config(['features.hub_transliterate' => false]);

        $res = $this->get('/sanskritorium')->assertOk();
        $res->assertSee('hub-transliterate-input', false);
        $res->assertSee('hub-result-deva', false);
        $res->assertSee('hub-result-iast', false);
        $res->assertSee('hub-result-slp1', false);
        $res->assertSee('hub-diacritics', false);
        $res->assertSee('sanskrit-util', false);
        $res->assertSee('rāma', false);
        $res->assertSee('Транслитерация санскрита', false);
        $res->assertSee('name="robots" content="index, follow"', false);
        $res->assertSee('Конвертер транслитерации санскрита', false);
        $res->assertDontSee('Демо · флаг', false);
        $res->assertSee(url('/sanskritorium'), false);
    }

    public function test_sanskritorium_reuses_the_h1463_playground_not_a_second_engine(): void
    {
        $res = $this->get('/sanskritorium')->assertOk();
        $res->assertSee('to_slp1', false);
        $res->assertSee('slp1_to_devanagari', false);
        $res->assertSee('iast_to_devanagari', false);
    }

    public function test_playground_js_does_not_call_iast_to_devanagari(): void
    {
        $js = file_get_contents(resource_path('js/transliterate.js'));
        $this->assertNotFalse($js);
        $this->assertMatchesRegularExpression(
            '/import\s*\{\s*to_slp1\s*,\s*slp1_to_devanagari\s*\}/',
            $js
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*import[^;]*iast_to_devanagari/m',
            $js
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![\w\/\* ])iast_to_devanagari\s*\(/',
            $js
        );
    }

    public function test_h1463_rama_fixture_round_trips_via_slp1_not_broken_helper(): void
    {
        if (! $this->nodeAvailable()) {
            $this->markTestSkipped('node is required to execute the vendored sanskrit-util fixture');
        }

        $jsPath = resource_path('js/vendor/sanskrit-util.js');
        $jsUrl = 'file:///'.str_replace('\\', '/', $jsPath);
        $runner = sys_get_temp_dir().DIRECTORY_SEPARATOR.'h2763_transliterate_fixture_'.getmypid().'.mjs';
        $src = <<<JS
import { to_slp1, slp1_to_devanagari, iast_to_devanagari } from {$this->jsString($jsUrl)};
const iast = 'rāma';
const slp1 = to_slp1(iast);
const deva = slp1_to_devanagari(slp1);
process.stdout.write(JSON.stringify({
  slp1,
  deva,
  broken: iast_to_devanagari(iast),
}));
JS;
        file_put_contents($runner, $src);

        try {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open(['node', $runner], $descriptors, $pipes, null, null);
            $this->assertIsResource($proc, 'Failed to start node fixture process');
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);

            $this->assertSame(0, $code, "node fixture failed: {$stderr}\nstdout={$stdout}");
            $decoded = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('rAma', $decoded['slp1']);
            $this->assertSame('राम', $decoded['deva']);
            $this->assertNotSame('राम', $decoded['broken']);
        } finally {
            if (is_file($runner)) {
                @unlink($runner);
            }
        }
    }

    public function test_sitemap_includes_sanskritorium(): void
    {
        Cache::forget('sitemap.xml');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(route('hub.sanskritorium'), false);
    }

    public function test_shop_footer_links_sanskritorium_without_touching_checkout(): void
    {
        $res = $this->get(route('shop.index'))->assertOk();
        $res->assertSee(route('hub.sanskritorium'), false);
        $res->assertSee('Транслитерация', false);
    }

    private function nodeAvailable(): bool
    {
        $out = [];
        $code = 1;
        @exec('node --version 2>&1', $out, $code);

        return $code === 0;
    }

    private function jsString(string $path): string
    {
        return json_encode($path, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
