<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The cabinet PWA could not be installed on any Chromium browser, and nothing said why.
 *
 * `manifest.webmanifest` declared `/images/logo.png` as both `192x192` and `512x512`,
 * but that file is 197x129. Chromium checks the decoded bitmap against the declared
 * `sizes` string and discards an icon that disagrees, which left only the 48x48 favicon
 * — below the mandatory 192 minimum — so the install entry never appeared and no error
 * surfaced to the user or the operator.
 *
 * These assertions are the install gate itself, not a proxy for it: a declared size that
 * does not match the real bitmap is exactly what the browser rejects.
 *
 * Regenerate the icons with `python scripts/generate_pwa_icons.py`.
 */
class PwaManifestIconsTest extends TestCase
{
    private const MIN_INSTALLABLE_PX = 192;

    /** Real pixel dimensions from a PNG's IHDR chunk. */
    private function pngSize(string $path): array
    {
        $handle = fopen($path, 'rb');
        $header = fread($handle, 24);
        fclose($handle);

        $this->assertSame("\x89PNG\r\n\x1a\n", substr($header, 0, 8), "Not a PNG: {$path}");
        $parsed = unpack('Nwidth/Nheight', substr($header, 16, 8));

        return [$parsed['width'], $parsed['height']];
    }

    private function manifest(): array
    {
        $path = public_path('manifest.webmanifest');
        $this->assertFileExists($path);

        $manifest = json_decode(file_get_contents($path), true);
        $this->assertIsArray($manifest, 'manifest.webmanifest is not valid JSON');

        return $manifest;
    }

    public function test_every_declared_png_icon_is_really_the_size_it_claims(): void
    {
        $icons = $this->manifest()['icons'] ?? [];
        $this->assertNotEmpty($icons, 'The manifest declares no icons at all');

        foreach ($icons as $icon) {
            $path = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($path, "Manifest icon is missing: {$icon['src']}");

            if (($icon['type'] ?? null) !== 'image/png') {
                continue; // .ico carries no IHDR; its size is not what gates installability
            }

            [$width, $height] = $this->pngSize($path);
            $this->assertSame(
                $icon['sizes'],
                "{$width}x{$height}",
                "{$icon['src']} declares sizes={$icon['sizes']} but the file is {$width}x{$height}. "
                    .'Chromium discards an icon whose bitmap contradicts its declared size.'
            );
        }
    }

    public function test_at_least_one_icon_meets_the_installability_minimum(): void
    {
        $usable = 0;

        foreach ($this->manifest()['icons'] ?? [] as $icon) {
            if (($icon['type'] ?? null) !== 'image/png') {
                continue;
            }
            if (! str_contains($icon['purpose'] ?? 'any', 'any')) {
                continue; // a maskable-only icon does not satisfy the install gate
            }

            [$width, $height] = $this->pngSize(public_path(ltrim($icon['src'], '/')));
            if ($width === $height && $width >= self::MIN_INSTALLABLE_PX) {
                $usable++;
            }
        }

        $this->assertGreaterThan(
            0,
            $usable,
            'No square purpose=any PNG icon of at least '.self::MIN_INSTALLABLE_PX.'px — '
                .'the browser will offer no install entry, silently.'
        );
    }

    public function test_a_dedicated_maskable_icon_exists_and_is_not_the_any_icon(): void
    {
        $maskable = [];
        $anySrcs = [];

        foreach ($this->manifest()['icons'] ?? [] as $icon) {
            $purpose = $icon['purpose'] ?? 'any';
            if (str_contains($purpose, 'maskable')) {
                $maskable[] = $icon;
            }
            if ($purpose === 'any') {
                $anySrcs[] = $icon['src'];
            }
        }

        $this->assertNotEmpty($maskable, 'No maskable icon; Android will letterbox the launcher icon.');

        foreach ($maskable as $icon) {
            // A landscape wordmark cannot serve both purposes: Android may crop a maskable
            // icon to a circle of 80% diameter, so the "any" art gets its edges cut off.
            $this->assertNotContains(
                $icon['src'],
                $anySrcs,
                "{$icon['src']} is declared both any and maskable; a maskable icon needs its own safe-zone padding."
            );
        }
    }

    public function test_the_student_layout_links_the_manifest_and_a_real_apple_touch_icon(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/student.blade.php'));

        $this->assertStringContainsString('rel="manifest"', $layout);

        preg_match('/rel="apple-touch-icon"[^>]*asset\(\'([^\']+)\'\)/', $layout, $match);
        $this->assertNotEmpty($match, 'No apple-touch-icon link in the student layout');

        [$width, $height] = $this->pngSize(public_path($match[1]));
        $this->assertSame(
            [180, 180],
            [$width, $height],
            "apple-touch-icon points at {$match[1]} ({$width}x{$height}); iOS expects 180x180."
        );
    }
}
