<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * H1488 — PWA shell smoke without booting Laravel.
 *
 * Worktrees often junction vendor/ from the main clone; Composer then maps App\
 * and Tests\ onto the main checkout, so Feature tests that call public_path()
 * assert the wrong tree. These checks read files relative to this test file
 * (always the checkout under test).
 */
class PwaShellAssetsTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @test */
    public function manifest_file_has_required_fields(): void
    {
        $path = $this->root().DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'manifest.webmanifest';
        $this->assertFileExists($path);

        $json = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($json);
        $this->assertSame('/dvaram', $json['start_url'] ?? null);
        $this->assertSame('standalone', $json['display'] ?? null);
        $this->assertSame('#E85C24', $json['theme_color'] ?? null);
        $this->assertNotEmpty($json['name'] ?? null);
        $this->assertIsArray($json['icons'] ?? null);
        $this->assertNotEmpty($json['icons']);
    }

    /** @test */
    public function offline_shell_and_service_worker_files_exist(): void
    {
        $offline = $this->root().DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'offline.html';
        $sw = $this->root().DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'sw.js';

        $this->assertFileExists($offline);
        $this->assertFileExists($sw);

        $offlineHtml = (string) file_get_contents($offline);
        $this->assertStringContainsString('Нет подключения', $offlineHtml);
        $this->assertStringContainsString('ОРС LMS', $offlineHtml);

        $swBody = (string) file_get_contents($sw);
        $this->assertStringContainsString('ors-cabinet-shell-v1', $swBody);
        $this->assertStringContainsString('/offline.html', $swBody);
    }

    /** @test */
    public function student_layout_links_manifest_and_registers_sw(): void
    {
        $layout = $this->root()
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'layouts'
            .DIRECTORY_SEPARATOR.'student.blade.php';

        $this->assertFileExists($layout);
        $html = (string) file_get_contents($layout);

        $this->assertStringContainsString('manifest.webmanifest', $html);
        $this->assertStringContainsString('theme-color', $html);
        $this->assertStringContainsString('serviceWorker', $html);
        $this->assertStringContainsString('sw.js', $html);
        $this->assertStringContainsString('viewport-fit=cover', $html);
        $this->assertStringContainsString('overflow-x-hidden', $html);

        // H4118 — iPhone-friendly P0 wave.
        $this->assertStringContainsString(
            'env(safe-area-inset',
            $html,
            'viewport-fit=cover без safe-area-отступов: шапка/контент залезают под «чёлку» и домой-полоску.'
        );
        $this->assertStringContainsString('interactive-widget=resizes-content', $html);
        $this->assertStringContainsString('color-scheme', $html);
        $this->assertStringContainsString('wire:loading', $html);
        $this->assertStringContainsString('wire:offline', $html);
        $this->assertStringContainsString('100dvh', $html);
        // Play CDN запрещён (H4118 root-cause): сломанный JIT v3.4.17 без hidden/md:*.
        $this->assertStringNotContainsString('cdn.tailwindcss.com', $html);
    }
}
