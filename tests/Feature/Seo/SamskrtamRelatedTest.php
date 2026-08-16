<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Course;
use App\Models\Tariff;
use App\Support\SamskrtamRelated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SamskrtamRelatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_allowlist_url_is_locked_200_on_samskrtam(): void
    {
        $allow = SamskrtamRelated::loadAllowlist();
        $this->assertArrayHasKey(SamskrtamRelated::HOME_KEY, $allow);
        $this->assertGreaterThanOrEqual(3, count($allow[SamskrtamRelated::HOME_KEY]));
        $this->assertArrayNotHasKey(SamskrtamRelated::DONATE_SLUG, $allow);

        $locked = SamskrtamRelated::lockedTwoHundred();
        $this->assertNotSame([], $locked);

        foreach (SamskrtamRelated::uniqueUrls() as $url) {
            $this->assertTrue(
                str_starts_with($url, 'https://samskrtam.ru/'),
                "Allowlist URL must stay on samskrtam.ru: {$url}"
            );
            $this->assertArrayHasKey($url, $locked, "Missing lock row for {$url}");
        }
    }

    public function test_homepage_renders_home_strip_and_keeps_org_jsonld(): void
    {
        $homeUrls = array_column(
            SamskrtamRelated::loadAllowlist()[SamskrtamRelated::HOME_KEY],
            'url'
        );
        $this->assertNotSame([], $homeUrls);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('id="samskrtam-related"', $html);
        $this->assertStringContainsString(SamskrtamRelated::HEADING, $html);
        $this->assertStringContainsString($homeUrls[0], $html);
        $this->assertStringContainsString('#org', $html);
    }

    public function test_mapped_course_page_renders_locked_href(): void
    {
        $course = Course::factory()->create([
            'slug' => 'grammatika-po-kocerginoi-gr62',
            'title' => 'Кочергина гр.62',
            'is_visible' => true,
        ]);
        Tariff::factory()->for($course)->create(['is_active' => true]);

        $html = $this->get('/k/'.$course->slug)->assertOk()->getContent();

        $this->assertStringContainsString('id="samskrtam-related"', $html);
        $this->assertStringContainsString('https://samskrtam.ru/p/kochergina/', $html);
        $this->assertStringContainsString(SamskrtamRelated::HEADING, $html);
    }

    public function test_unmapped_course_and_donate_do_not_render_block(): void
    {
        $unmapped = Course::factory()->create([
            'slug' => 'unmapped-hymn-fixture',
            'title' => 'Unmapped hymn',
            'is_visible' => true,
        ]);
        Tariff::factory()->for($unmapped)->create(['is_active' => true]);

        $donate = Course::factory()->create([
            'slug' => SamskrtamRelated::DONATE_SLUG,
            'title' => 'Донат на развитие ОРС',
            'is_visible' => true,
        ]);
        Tariff::factory()->for($donate)->create(['is_active' => true]);

        $this->get('/k/'.$unmapped->slug)
            ->assertOk()
            ->assertDontSee('id="samskrtam-related"', false)
            ->assertDontSee(SamskrtamRelated::HEADING, false);

        $this->get('/k/'.$donate->slug)
            ->assertOk()
            ->assertDontSee('id="samskrtam-related"', false)
            ->assertDontSee(SamskrtamRelated::HEADING, false);
    }

    public function test_url_missing_from_lockfile_does_not_render(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'seo-h2918-'.uniqid('', true);
        mkdir($dir);
        $allow = $dir.DIRECTORY_SEPARATOR.'allow.json';
        $lock = $dir.DIRECTORY_SEPARATOR.'lock.json';
        file_put_contents($allow, json_encode([
            '_home' => [
                ['url' => 'https://samskrtam.ru/s-chego-nachat-sanskrit/', 'anchor' => 'Start'],
                ['url' => 'https://samskrtam.ru/not-in-the-lock/', 'anchor' => 'Ghost'],
            ],
        ], JSON_UNESCAPED_SLASHES));
        file_put_contents($lock, json_encode([
            'urls' => [
                [
                    'url' => 'https://samskrtam.ru/s-chego-nachat-sanskrit/',
                    'status' => 200,
                    'fetched_at' => '2026-08-16T19:00:00+00:00',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES));

        $links = SamskrtamRelated::linksFor('_home', $allow, $lock);
        $this->assertCount(1, $links);
        $this->assertSame('https://samskrtam.ru/s-chego-nachat-sanskrit/', $links[0]['url']);
        $this->assertSame(['Start'], array_column($links, 'anchor'));
    }

    public function test_artisan_writes_lock_when_every_url_is_200(): void
    {
        Http::fake([
            'https://samskrtam.ru/*' => Http::response('ok', 200),
        ]);

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'seo-h2918-lock-'.uniqid('', true);
        mkdir($dir);
        $allow = $dir.DIRECTORY_SEPARATOR.'allow.json';
        $lock = $dir.DIRECTORY_SEPARATOR.'allow.lock.json';
        file_put_contents($allow, json_encode([
            '_home' => [
                ['url' => 'https://samskrtam.ru/s-chego-nachat-sanskrit/', 'anchor' => 'A'],
                ['url' => 'https://samskrtam.ru/uchebniki-sanskrita/', 'anchor' => 'B'],
                ['url' => 'https://samskrtam.ru/sanskrit-chto-eto/', 'anchor' => 'C'],
            ],
        ], JSON_UNESCAPED_SLASHES));

        $this->artisan('seo:lock-samskrtam-related', [
            'json' => $allow,
            '--lock' => $lock,
        ])->assertSuccessful();

        $this->assertFileExists($lock);
        $decoded = json_decode((string) file_get_contents($lock), true);
        $this->assertIsArray($decoded);
        $this->assertCount(3, $decoded['urls']);
        foreach ($decoded['urls'] as $row) {
            $this->assertSame(200, $row['status']);
        }
        $raw = (string) file_get_contents($lock);
        $this->assertFalse(str_starts_with($raw, "\xEF\xBB\xBF"));
    }

    public function test_artisan_exits_1_when_any_url_is_not_200(): void
    {
        Http::fake([
            'https://samskrtam.ru/ok/' => Http::response('ok', 200),
            'https://samskrtam.ru/missing/' => Http::response('no', 404),
        ]);

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'seo-h2918-fail-'.uniqid('', true);
        mkdir($dir);
        $allow = $dir.DIRECTORY_SEPARATOR.'allow.json';
        $lock = $dir.DIRECTORY_SEPARATOR.'allow.lock.json';
        file_put_contents($allow, json_encode([
            '_home' => [
                ['url' => 'https://samskrtam.ru/ok/', 'anchor' => 'A'],
                ['url' => 'https://samskrtam.ru/missing/', 'anchor' => 'B'],
                ['url' => 'https://samskrtam.ru/ok/', 'anchor' => 'C'],
            ],
        ], JSON_UNESCAPED_SLASHES));

        $this->artisan('seo:lock-samskrtam-related', [
            'json' => $allow,
            '--lock' => $lock,
        ])->assertFailed();

        $this->assertFileDoesNotExist($lock);
    }

    public function test_artisan_rejects_bom_and_foreign_host(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'seo-h2918-bom-'.uniqid('', true);
        mkdir($dir);
        $bom = $dir.DIRECTORY_SEPARATOR.'bom.json';
        file_put_contents($bom, "\xEF\xBB\xBF".json_encode([
            '_home' => [
                ['url' => 'https://samskrtam.ru/ok/', 'anchor' => 'A'],
                ['url' => 'https://samskrtam.ru/ok2/', 'anchor' => 'B'],
                ['url' => 'https://samskrtam.ru/ok3/', 'anchor' => 'C'],
            ],
        ]));
        $this->artisan('seo:lock-samskrtam-related', ['json' => $bom])->assertFailed();

        $foreign = $dir.DIRECTORY_SEPARATOR.'foreign.json';
        file_put_contents($foreign, json_encode([
            '_home' => [
                ['url' => 'https://example.com/not-ours/', 'anchor' => 'A'],
                ['url' => 'https://samskrtam.ru/ok/', 'anchor' => 'B'],
                ['url' => 'https://samskrtam.ru/ok2/', 'anchor' => 'C'],
            ],
        ], JSON_UNESCAPED_SLASHES));
        $this->artisan('seo:lock-samskrtam-related', ['json' => $foreign])->assertFailed();
    }
}
