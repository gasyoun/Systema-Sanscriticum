<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1488 — PWA shell smoke.
 *
 * Laravel's HTTP kernel does not serve public/ static files the way nginx does,
 * so shell assets are asserted on disk; the student layout is asserted via an
 * authenticated cabinet render.
 */
class PwaManifestTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function manifest_file_has_required_fields(): void
    {
        $path = public_path('manifest.webmanifest');
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
        $offline = public_path('offline.html');
        $sw = public_path('sw.js');

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
        $student = User::factory()->create(['is_admin' => false]);

        $html = $this->actingAs($student)
            ->get('/dvaram')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('manifest.webmanifest', $html);
        $this->assertStringContainsString('theme-color', $html);
        $this->assertStringContainsString('serviceWorker', $html);
        $this->assertStringContainsString('sw.js', $html);
        $this->assertStringContainsString('viewport-fit=cover', $html);
    }
}