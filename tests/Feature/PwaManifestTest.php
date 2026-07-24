<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1488 — PWA shell smoke: manifest + offline page + SW + student layout link.
 */
class PwaManifestTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function manifest_is_served_with_required_fields(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $response->assertOk();

        $json = $response->json();
        $this->assertIsArray($json);
        $this->assertSame('/dvaram', $json['start_url'] ?? null);
        $this->assertSame('standalone', $json['display'] ?? null);
        $this->assertSame('#E85C24', $json['theme_color'] ?? null);
        $this->assertNotEmpty($json['name'] ?? null);
        $this->assertNotEmpty($json['icons'] ?? null);
        $this->assertIsArray($json['icons']);
    }

    /** @test */
    public function offline_shell_page_is_served(): void
    {
        $this->get('/offline.html')
            ->assertOk()
            ->assertSee('Нет подключения', false)
            ->assertSee('ОРС LMS', false);
    }

    /** @test */
    public function service_worker_script_is_served(): void
    {
        $this->get('/sw.js')
            ->assertOk()
            ->assertSee('ors-cabinet-shell-v1', false)
            ->assertSee('/offline.html', false);
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
    }
}
